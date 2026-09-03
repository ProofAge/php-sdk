<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Tests\Http\Body;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProofAge\Sdk\Http\Body\FilePart;
use ProofAge\Sdk\Http\Body\MultipartBody;
use ProofAge\Sdk\Http\Body\RawBody;

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
