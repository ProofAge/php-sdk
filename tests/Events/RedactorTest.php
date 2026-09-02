<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Tests\Events;

use PHPUnit\Framework\TestCase;
use ProofAge\Sdk\Events\Redactor;
use ProofAge\Sdk\Http\Body\FilePart;
use ProofAge\Sdk\Http\Body\MultipartBody;
use ProofAge\Sdk\Http\Body\RawBody;

class RedactorTest extends TestCase
{
    public function test_api_key_and_signature_are_masked_and_everything_else_is_verbatim(): void
    {
        $headers = Redactor::headers([
            'Accept' => 'application/json',
            'x-api-key' => 'pk_live_0123456789abcdef',
            'X-HMAC-Signature' => str_repeat('4c6daa63', 8),
            'X-Request-Id' => 'req-1',
        ]);

        $this->assertSame([
            'Accept' => 'application/json',
            'x-api-key' => '****cdef',
            'X-HMAC-Signature' => '4c6daa63...',
            'X-Request-Id' => 'req-1',
        ], $headers);
    }

    public function test_a_short_api_key_is_still_masked(): void
    {
        $this->assertSame(['X-API-Key' => '****ab'], Redactor::headers(['X-API-Key' => 'ab']));
    }

    public function test_no_body(): void
    {
        $this->assertSame(['kind' => 'none'], Redactor::body(null));
    }

    public function test_a_raw_body_is_reduced_to_size_and_hash(): void
    {
        $this->assertSame(
            ['kind' => 'json', 'bytes' => 7, 'sha256' => hash('sha256', '{"a":1}')],
            Redactor::body(new RawBody('{"a":1}')),
        );
    }

    public function test_a_multipart_body_keeps_fields_and_reduces_files_to_metadata(): void
    {
        $body = new MultipartBody(
            ['type' => 'document', 'side' => 'front', 'device_info' => ['os' => 'iOS']],
            [new FilePart('file', 'front.jpg', 'jpeg-bytes')],
        );

        $this->assertSame([
            'kind' => 'multipart',
            'fields' => ['type' => 'document', 'side' => 'front', 'device_info' => ['os' => 'iOS']],
            'files' => [['name' => 'file', 'filename' => 'front.jpg', 'bytes' => 10, 'sha256' => hash('sha256', 'jpeg-bytes')]],
        ], Redactor::body($body));
    }
}
