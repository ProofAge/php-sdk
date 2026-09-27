<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Tests\Http\Body;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProofAge\Sdk\Http\Body\FilePart;
use ProofAge\Sdk\Http\Body\MultipartBody;
use ProofAge\Sdk\Http\Body\RawBody;
use ProofAge\Sdk\Signing\Signer;

class BodiesTest extends TestCase
{
    public function test_raw_body_holds_bytes_and_defaults_to_json(): void
    {
        $body = new RawBody('{"a":1}');

        $this->assertSame('{"a":1}', $body->bytes);
        $this->assertSame('application/json', $body->contentType);
        $this->assertSame('text/plain', (new RawBody('x', 'text/plain'))->contentType);
    }

    public function test_multipart_body_exposes_fields_files_and_file_hashes_in_file_order(): void
    {
        $front = new FilePart('file', 'front.jpg', 'front');
        $back = new FilePart('file_back', 'back.jpg', 'back');

        $body = new MultipartBody(['type' => 'document'], [$front, $back]);

        $this->assertSame(['type' => 'document'], $body->fields);
        $this->assertSame([$front, $back], $body->files);
        $this->assertSame([$front->sha256(), $back->sha256()], $body->fileHashes());
    }

    public function test_field_values_are_normalized_to_what_the_server_reads_back(): void
    {
        $body = new MultipartBody([
            'type' => 'selfie',
            'fingerprint' => null,
            'in_iframe' => false,
            'retry' => true,
            'head_turn_step' => 3,
            'ratio' => 1.5,
            'label' => new class implements \Stringable
            {
                public function __toString(): string
                {
                    return 'stringable';
                }
            },
            'device' => ['memory' => null, 'touch' => false, 'screen' => ['w' => 390, 'h' => null]],
            'gone' => ['only' => null],
            'empty' => [],
            'blank' => '',
        ], []);

        $this->assertSame([
            'type' => 'selfie',
            'in_iframe' => '0',
            'retry' => '1',
            'head_turn_step' => '3',
            'ratio' => '1.5',
            'label' => 'stringable',
            'device' => ['touch' => '0', 'screen' => ['w' => '390']],
            'blank' => '',
        ], $body->fields);
    }

    public function test_a_transport_that_casts_every_value_to_a_string_sends_what_was_signed(): void
    {
        // Guzzle's multipart and Illuminate's attach() cast each value to a string: null and
        // false both become "". The server signs http_build_query() over what it decoded, which
        // omits a missing field and keeps "". Built from the normalized fields, the two agree.
        $fields = ['type' => 'selfie', 'fingerprint' => null, 'in_iframe' => false, 'retry' => true, 'device' => ['memory' => null, 'touch' => false]];
        $body = new MultipartBody($fields, []);

        $castByTransport = self::castLeaves($body->fields);
        $serverCanonical = Signer::canonicalMultipart('POST', '/v1/verifications/v/media', $castByTransport, []);

        $this->assertSame(Signer::canonicalMultipart('POST', '/v1/verifications/v/media', $body->fields, []), $serverCanonical);
        $this->assertNotSame(
            Signer::canonicalMultipart('POST', '/v1/verifications/v/media', $fields, []),
            Signer::canonicalMultipart('POST', '/v1/verifications/v/media', self::castLeaves($fields), []),
            'Unnormalized, a string-casting transport and the signer disagree - the bug this guards.',
        );
    }

    public function test_a_field_value_that_cannot_be_sent_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"meta"');

        new MultipartBody(['meta' => new \stdClass], []);
    }

    /**
     * @param  array<int|string, mixed>  $fields
     * @return array<int|string, mixed>
     */
    private static function castLeaves(array $fields): array
    {
        return array_map(static fn (mixed $value): mixed => is_array($value) ? self::castLeaves($value) : (string) $value, $fields);
    }

    /** @return iterable<string, array{string}> */
    public static function fieldNamesPhpMangles(): iterable
    {
        // PHP registers request variables under a name it rewrites: dots and spaces become
        // underscores, leading spaces are dropped, brackets nest. The server then
        // canonicalizes $_POST under the rewritten name and the signature no longer matches.
        // The rule applied is PHP's variable-name rule, which everything PHP rewrites fails
        // and which is stable and easy to state; a dash is rejected by that rule even though
        // PHP would keep it.
        yield 'dot' => ['a.b'];
        yield 'space' => ['a b'];
        yield 'leading space' => [' lead'];
        yield 'open bracket' => ['a[b'];
        yield 'array suffix' => ['tags[]'];
        yield 'quote' => ['a"b'];
        yield 'digit first' => ['0'];
        yield 'empty' => [''];
        yield 'dash' => ['content-type'];
    }

    #[DataProvider('fieldNamesPhpMangles')]
    public function test_a_top_level_field_name_php_would_rename_is_rejected(string $name): void
    {
        try {
            new MultipartBody([$name => 'x'], []);
            $this->fail('Expected an InvalidArgumentException for the form field.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('field name', $e->getMessage());
        }

        try {
            new MultipartBody([], [new FilePart($name, 'x.jpg', 'x')]);
            $this->fail('Expected an InvalidArgumentException for the file field.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('field name', $e->getMessage());
        }
    }

    public function test_names_that_survive_php_are_accepted_and_nested_keys_are_not_restricted(): void
    {
        $body = new MultipartBody(
            ['type' => 'document', '_private' => 1, 'x1' => true, 'Jürgen' => 'ok', 'device_info' => ['screen.size' => '390 x 844', 'a b' => 1]],
            [new FilePart('file', 'front.jpg', 'x'), new FilePart('file_back', 'back.jpg', 'y')],
        );

        $this->assertCount(5, $body->fields);
        $this->assertCount(2, $body->files);
    }

    public function test_two_files_under_one_field_name_are_rejected(): void
    {
        // PHP keeps one upload per field name, so the server would hash one file while two
        // hashes were signed.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"file"');

        new MultipartBody([], [new FilePart('file', 'front.jpg', 'x'), new FilePart('file', 'back.jpg', 'y')]);
    }
}
