<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Tests\Http\Curl;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ProofAge\Sdk\Exceptions\TransportException;
use ProofAge\Sdk\Http\Body\FilePart;
use ProofAge\Sdk\Http\Body\MultipartBody;
use ProofAge\Sdk\Http\Body\RawBody;
use ProofAge\Sdk\Http\Curl\CurlHttpClient;
use ProofAge\Sdk\Http\HttpClient;
use ProofAge\Sdk\Http\Request;
use ProofAge\Sdk\Http\Response;
use ProofAge\Sdk\Http\RetryPolicy;
use ProofAge\Sdk\Signing\Signer;
use ProofAge\Sdk\Tests\Support\EchoServer;

#[Group('network-local')]
class CurlHttpClientTest extends TestCase
{
    private static EchoServer $server;

    public static function setUpBeforeClass(): void
    {
        self::$server = EchoServer::start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
    }

    /** @param array<string, string> $headers */
    private function request(string $method, string $path, RawBody|MultipartBody|null $body = null, array $headers = [], int $timeout = 10, ?string $sink = null, bool $stream = false): Request
    {
        return new Request($method, self::$server->url($path), $path, $headers, $body, RetryPolicy::download(), $timeout, $sink, $stream);
    }

    /** @return array<string, mixed> */
    private function echo(Response $response): array
    {
        $decoded = $response->json();
        $this->assertIsArray($decoded, 'Echo server did not answer with JSON: '.$response->body());

        return $decoded;
    }

    public function test_it_is_an_http_client(): void
    {
        $this->assertInstanceOf(HttpClient::class, new CurlHttpClient);
    }

    public function test_get_sends_method_path_query_and_headers(): void
    {
        $request = $this->request('GET', '/v1/workspace?b=2&a=1', headers: ['X-API-Key' => 'key-1', 'Accept' => 'application/json']);

        $response = (new CurlHttpClient)->send($request);
        $echo = $this->echo($response);

        $this->assertSame(200, $response->status());
        $this->assertSame('GET', $echo['method']);
        $this->assertSame('/v1/workspace', $echo['path']);
        $this->assertSame('b=2&a=1', $echo['query']);
        $this->assertSame('key-1', $echo['headers']['x-api-key']);
        $this->assertSame('application/json', $echo['headers']['accept']);
        $this->assertSame(0, $echo['body_length']);
        $this->assertSame($request, $response->request);
        $this->assertSame(0.0, $response->durationMs, 'Transports do not measure time; SignMiddleware does.');
    }

    public function test_response_headers_are_lower_cased_and_multi_valued(): void
    {
        $response = (new CurlHttpClient)->send($this->request('GET', '/v1/x'));

        $this->assertSame('application/json', $response->header('Content-Type'));
        $this->assertSame('1', $response->header('x-echo'));
        $this->assertSame(['a=1', 'b=2'], $response->headers()['set-cookie']);
    }

    public function test_raw_body_bytes_arrive_unchanged_with_their_content_type(): void
    {
        $bytes = '{"callback_url":"https:\/\/example.com\/hook","name":"Jürgen"}';

        $echo = $this->echo((new CurlHttpClient)->send($this->request('POST', '/v1/verifications', new RawBody($bytes))));

        $this->assertSame('POST', $echo['method']);
        $this->assertSame($bytes, base64_decode($echo['body']));
        $this->assertSame(hash('sha256', $bytes), $echo['body_sha256']);
        $this->assertSame('application/json', $echo['headers']['content-type']);
    }

    public function test_an_explicit_content_type_header_wins_over_the_body_default(): void
    {
        $echo = $this->echo((new CurlHttpClient)->send($this->request('POST', '/v1/x', new RawBody('a=1', 'application/x-www-form-urlencoded'), ['Content-Type' => 'text/plain'])));

        $this->assertSame('text/plain', $echo['headers']['content-type']);
    }

    public function test_bodyless_post_sends_an_empty_body(): void
    {
        $echo = $this->echo((new CurlHttpClient)->send($this->request('POST', '/v1/verifications/ver_1/submit')));

        $this->assertSame('POST', $echo['method']);
        $this->assertSame(0, $echo['body_length']);
    }

    public function test_the_signature_verifies_against_the_bytes_the_server_received(): void
    {
        $secret = 'transport-secret';
        $bytes = json_encode(['reason' => 'Said "no" — Jürgen/2026']);
        $path = '/v1/verifications/ver_1/blocked-face';
        $signature = (new Signer($secret))->signRaw('POST', $path, (string) $bytes);

        $echo = $this->echo((new CurlHttpClient)->send($this->request('POST', $path, new RawBody((string) $bytes), ['X-HMAC-Signature' => $signature])));

        // Recomputed from what the server received, not from what the test sent.
        $received = base64_decode($echo['body']);
        $this->assertSame(hash_hmac('sha256', 'POST'.$echo['path'].$received, $secret), $echo['headers']['x-hmac-signature']);
    }

    public function test_multipart_fields_and_files_arrive_and_hash_as_signed(): void
    {
        $front = new FilePart('file', 'front.jpg', random_bytes(2048));
        $back = new FilePart('file_back', 'back.jpg', random_bytes(1024), 'image/jpeg');
        $fields = ['type' => 'document', 'side' => 'front', 'device_info' => ['os' => 'iOS', 'screen' => ['w' => 390]], 'note' => 'a=b&c d'];
        $body = new MultipartBody($fields, [$front, $back]);
        $path = '/v1/verifications/ver_1/media';
        $signature = (new Signer('transport-secret'))->signMultipart('POST', $path, $fields, $body->fileHashes());

        $echo = $this->echo((new CurlHttpClient)->send($this->request('POST', $path, $body, ['X-HMAC-Signature' => $signature])));

        $this->assertStringStartsWith('multipart/form-data; boundary=', $echo['headers']['content-type']);
        $this->assertSame(['type' => 'document', 'side' => 'front', 'device_info' => ['os' => 'iOS', 'screen' => ['w' => '390']], 'note' => 'a=b&c d'], $echo['fields']);
        $this->assertSame([
            ['field' => 'file', 'filename' => 'front.jpg', 'size' => 2048, 'type' => 'image/jpeg', 'sha256' => $front->sha256()],
            ['field' => 'file_back', 'filename' => 'back.jpg', 'size' => 1024, 'type' => 'image/jpeg', 'sha256' => $back->sha256()],
        ], $echo['files']);

        // The canonical string rebuilt from the echoed fields and file hashes signs to the header sent.
        $rebuilt = (new Signer('transport-secret'))->signMultipart('POST', $echo['path'], $echo['fields'], array_column($echo['files'], 'sha256'));
        $this->assertSame($rebuilt, $echo['headers']['x-hmac-signature']);
    }

    public function test_http_error_statuses_are_responses_not_exceptions(): void
    {
        foreach ([401, 422, 429, 500] as $status) {
            $response = (new CurlHttpClient)->send($this->request('GET', "/status/{$status}"));

            $this->assertSame($status, $response->status());
            $this->assertSame('STATUS_'.$status, $response->json()['error']['code']);
        }
    }

    public function test_redirects_are_not_followed(): void
    {
        $response = (new CurlHttpClient)->send($this->request('GET', '/status/302'));

        $this->assertSame(302, $response->status());
        $this->assertSame('/v1/workspace', $response->header('location'));
    }

    public function test_timeout_throws_a_transport_exception_with_the_curl_errno(): void
    {
        try {
            (new CurlHttpClient)->send($this->request('GET', '/slow', timeout: 1));
            $this->fail('Expected a TransportException.');
        } catch (TransportException $e) {
            $this->assertSame(CURLE_OPERATION_TIMEDOUT, $e->getCode());
            $this->assertNotSame('', $e->getMessage());
        }
    }

    public function test_connection_refused_throws_a_transport_exception_with_the_curl_errno(): void
    {
        $port = EchoServer::freePort();
        $request = new Request('GET', "http://127.0.0.1:{$port}/v1/workspace", '/v1/workspace', [], null, RetryPolicy::download(), 5);

        try {
            (new CurlHttpClient)->send($request);
            $this->fail('Expected a TransportException.');
        } catch (TransportException $e) {
            $this->assertSame(CURLE_COULDNT_CONNECT, $e->getCode());
        }
    }

    public function test_the_body_is_buffered_in_a_rewound_temp_stream(): void
    {
        $response = (new CurlHttpClient)->send($this->request('GET', '/bytes/1000', stream: true));

        $stream = $response->getBody();
        $this->assertSame('image/jpeg', $response->header('content-type'));
        $this->assertSame(0, $stream->tell());
        $this->assertSame(1000, $stream->getSize());
        $this->assertSame(substr(str_repeat('0123456789abcdef', 63), 0, 1000), $stream->getContents());
        $this->assertStringStartsWith('php://temp', (string) $stream->getMetadata('uri'));
    }

    public function test_a_body_larger_than_two_megabytes_is_still_complete(): void
    {
        $length = 3_000_000;
        $expected = hash('sha256', substr(str_repeat('0123456789abcdef', intdiv($length, 16) + 1), 0, $length));

        $response = (new CurlHttpClient)->send($this->request('GET', "/bytes/{$length}", stream: true));

        $this->assertSame($length, $response->getBody()->getSize());
        $this->assertSame($expected, hash('sha256', $response->getBody()->getContents()));
    }

    public function test_a_sink_receives_the_body_and_the_response_streams_read_only_from_it(): void
    {
        $path = sys_get_temp_dir().'/proofage-curl-sink-'.uniqid().'.jpg';

        $response = (new CurlHttpClient)->send($this->request('GET', '/bytes/100', sink: $path));

        $this->assertSame(200, $response->status());
        $this->assertSame(substr(str_repeat('0123456789abcdef', 7), 0, 100), file_get_contents($path));
        $this->assertSame($path, $response->getBody()->getMetadata('uri'));
        $this->assertFalse($response->getBody()->isWritable());
        $this->assertSame(100, $response->getBody()->getSize());

        $response->getBody()->close();
        unlink($path);
    }

    public function test_an_empty_url_is_rejected_before_anything_is_sent(): void
    {
        $request = new Request('GET', '', '/v1/workspace', [], null, RetryPolicy::download(), 5);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Request URL is empty');

        (new CurlHttpClient)->send($request);
    }
}
