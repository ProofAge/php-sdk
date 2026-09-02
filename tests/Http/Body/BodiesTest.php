<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Tests\Http\Body;

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
}
