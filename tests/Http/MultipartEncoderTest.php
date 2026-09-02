<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Tests\Http;

use PHPUnit\Framework\TestCase;
use ProofAge\Sdk\Http\Body\FilePart;
use ProofAge\Sdk\Http\Body\MultipartBody;
use ProofAge\Sdk\Http\MultipartEncoder;

class MultipartEncoderTest extends TestCase
{
    /**
     * Splits an RFC 7578 body into parts: [headers => [name => value], body => string].
     *
     * @return list<array{headers: array<string, string>, body: string}>
     */
    private function parse(string $encoded, string $boundary): array
    {
        $this->assertStringEndsWith("--{$boundary}--\r\n", $encoded);

        $chunks = explode("--{$boundary}\r\n", substr($encoded, 0, -strlen("--{$boundary}--\r\n")));
        $this->assertSame('', array_shift($chunks), 'Body must start with the first boundary.');

        $parts = [];

        foreach ($chunks as $chunk) {
            $this->assertStringEndsWith("\r\n", $chunk);
            [$rawHeaders, $body] = explode("\r\n\r\n", substr($chunk, 0, -2), 2);
            $headers = [];

            foreach (explode("\r\n", $rawHeaders) as $line) {
                [$name, $value] = explode(': ', $line, 2);
                $headers[$name] = $value;
            }

            $parts[] = ['headers' => $headers, 'body' => $body];
        }

        return $parts;
    }

    public function test_boundary_is_random_and_appears_in_the_content_type(): void
    {
        $a = new MultipartEncoder;
        $b = new MultipartEncoder;

        $this->assertNotSame($a->boundary(), $b->boundary());
        $this->assertSame('multipart/form-data; boundary='.$a->boundary(), $a->contentType());
    }

    public function test_encodes_fields_then_files(): void
    {
        $encoder = new MultipartEncoder('b0undary');
        $body = new MultipartBody(
            ['type' => 'document', 'side' => 'front'],
            [new FilePart('file', 'front.jpg', "\xFF\xD8jpeg-bytes", 'image/jpeg')],
        );

        $parts = $this->parse($encoder->encode($body), 'b0undary');

        $this->assertSame([
            ['headers' => ['Content-Disposition' => 'form-data; name="type"'], 'body' => 'document'],
            ['headers' => ['Content-Disposition' => 'form-data; name="side"'], 'body' => 'front'],
            ['headers' => ['Content-Disposition' => 'form-data; name="file"; filename="front.jpg"', 'Content-Type' => 'image/jpeg'], 'body' => "\xFF\xD8jpeg-bytes"],
        ], $parts);
    }

    public function test_nested_fields_are_flattened_with_bracket_names_and_scalars_match_http_build_query(): void
    {
        $encoder = new MultipartEncoder('b');
        $body = new MultipartBody(
            ['device_info' => ['os' => 'iOS', 'screen' => ['w' => 390]], 'flag' => true, 'off' => false, 'n' => 1.5, 'skip' => null],
            [],
        );

        $parts = $this->parse($encoder->encode($body), 'b');
        $seen = [];

        foreach ($parts as $part) {
            $seen[$part['headers']['Content-Disposition']] = $part['body'];
        }

        $this->assertSame([
            'form-data; name="device_info[os]"' => 'iOS',
            'form-data; name="device_info[screen][w]"' => '390',
            'form-data; name="flag"' => '1',
            'form-data; name="off"' => '0',
            'form-data; name="n"' => '1.5',
        ], $seen);
    }

    public function test_file_content_type_is_guessed_from_the_extension_or_falls_back_to_octet_stream(): void
    {
        $encoder = new MultipartEncoder('b');
        $body = new MultipartBody([], [
            new FilePart('a', 'selfie.JPG', 'x'),
            new FilePart('b', 'doc.png', 'x'),
            new FilePart('c', 'blob', 'x'),
        ]);

        $parts = $this->parse($encoder->encode($body), 'b');

        $this->assertSame('image/jpeg', $parts[0]['headers']['Content-Type']);
        $this->assertSame('image/png', $parts[1]['headers']['Content-Type']);
        $this->assertSame('application/octet-stream', $parts[2]['headers']['Content-Type']);
    }

    public function test_quotes_and_line_breaks_in_names_are_neutralised(): void
    {
        $encoder = new MultipartEncoder('b');
        $body = new MultipartBody([], [new FilePart('file', "we\"ird\r\nname.jpg", 'x')]);

        $parts = $this->parse($encoder->encode($body), 'b');

        $this->assertSame('form-data; name="file"; filename="we%22irdname.jpg"', $parts[0]['headers']['Content-Disposition']);
    }
}
