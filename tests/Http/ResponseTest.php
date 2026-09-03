<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Tests\Http;

use PHPUnit\Framework\TestCase;
use ProofAge\Sdk\Http\Request;
use ProofAge\Sdk\Http\Response;
use ProofAge\Sdk\Http\RetryPolicy;
use ProofAge\Sdk\Stream\ResourceStream;

class ResponseTest extends TestCase
{
    private function request(int $attempt = 1): Request
    {
        return new Request('GET', 'https://api.test.com/v1/workspace', '/v1/workspace', [], null, RetryPolicy::interactive(), 30, attempt: $attempt);
    }

    private function response(int $status, string $body = '', array $headers = []): Response
    {
        return new Response($status, $headers, ResourceStream::fromString($body), $this->request());
    }

    public function test_status_helpers(): void
    {
        $this->assertSame(200, $this->response(200)->status());
        $this->assertTrue($this->response(200)->successful());
        $this->assertTrue($this->response(204)->successful());
        $this->assertTrue($this->response(200)->ok());
        $this->assertFalse($this->response(204)->ok());
        $this->assertFalse($this->response(200)->failed());
        $this->assertFalse($this->response(301)->successful());
        $this->assertFalse($this->response(301)->failed());
        $this->assertTrue($this->response(422)->failed());
        $this->assertTrue($this->response(500)->failed());
    }

    /**
     * Names keep the case the server sent: `isset($response->headers()['Content-Type'])`
     * used to be silently false because every name was lower-cased.
     */
    public function test_headers_keep_the_case_the_server_sent_with_list_values(): void
    {
        $response = $this->response(200, '', ['Content-Type' => 'application/json', 'X-Multi' => ['a', 'b']]);

        $this->assertSame(['Content-Type' => ['application/json'], 'X-Multi' => ['a', 'b']], $response->headers());
        $this->assertSame(['Content-Type' => ['application/json'], 'X-Multi' => ['a', 'b']], $response->headers);
        $this->assertTrue(isset($response->headers()['Content-Type']));
    }

    public function test_the_same_header_in_two_spellings_merges_under_the_first(): void
    {
        $response = $this->response(200, '', ['Set-Cookie' => 'a=1', 'set-cookie' => ['b=2']]);

        $this->assertSame(['Set-Cookie' => ['a=1', 'b=2']], $response->headers());
    }

    public function test_header_returns_the_first_value_case_insensitively(): void
    {
        $response = $this->response(200, '', ['X-Multi' => ['a', 'b']]);

        $this->assertSame('a', $response->header('x-multi'));
        $this->assertSame('a', $response->header('X-MULTI'));
        $this->assertSame('a', $response->header('X-Multi'));
        $this->assertNull($response->header('missing'));
    }

    public function test_body_reads_the_whole_stream_and_can_be_read_again(): void
    {
        $response = $this->response(200, '{"id":"ws_1"}');

        $this->assertSame('{"id":"ws_1"}', $response->body());
        $this->assertSame('{"id":"ws_1"}', $response->body());
    }

    public function test_json_decodes_to_an_associative_array(): void
    {
        $this->assertSame(['id' => 'ws_1', 'n' => [1, 2]], $this->response(200, '{"id":"ws_1","n":[1,2]}')->json());
    }

    public function test_json_is_null_for_an_empty_or_invalid_body(): void
    {
        $this->assertNull($this->response(204)->json());
        $this->assertNull($this->response(200, 'not json')->json());
    }

    /**
     * Illuminate\Http\Client\Response::json($key, $default) takes a dot path, and PHP
     * ignores extra arguments, so before this `$response->json('error.code')` silently
     * returned the whole document instead of the value.
     */
    public function test_json_with_a_dot_path_returns_the_nested_value(): void
    {
        $response = $this->response(404, '{"error":{"code":"MEDIA_NOT_FOUND","message":"Media not found"},"errors":{"file":["required","image"]},"n":null}');

        $this->assertSame('MEDIA_NOT_FOUND', $response->json('error.code'));
        $this->assertSame(['code' => 'MEDIA_NOT_FOUND', 'message' => 'Media not found'], $response->json('error'));
        $this->assertSame('image', $response->json('errors.file.1'));
        $this->assertNull($response->json('n', 'default'), 'A key that is present with a null value is null, not the default.');
    }

    public function test_json_with_a_missing_path_returns_the_default(): void
    {
        $response = $this->response(200, '{"error":{"code":"X"}}');

        $this->assertNull($response->json('missing'));
        $this->assertSame('fallback', $response->json('missing', 'fallback'));
        $this->assertSame('fallback', $response->json('error.code.deeper', 'fallback'), 'Descending into a scalar yields the default.');
        $this->assertSame('fallback', $this->response(204)->json('anything', 'fallback'));
        $this->assertSame('fallback', $this->response(200, 'not json')->json('anything', 'fallback'));
    }

    public function test_json_signature_matches_illuminate(): void
    {
        $method = new \ReflectionMethod(Response::class, 'json');
        $parameters = array_map(static fn (\ReflectionParameter $p): string => $p->getName(), $method->getParameters());

        $this->assertSame(['key', 'default'], $parameters);
        $this->assertTrue($method->getParameters()[0]->isDefaultValueAvailable());
        $this->assertTrue($method->getParameters()[1]->isDefaultValueAvailable());
    }

    public function test_get_body_returns_the_stream(): void
    {
        $stream = ResourceStream::fromString('bytes');

        $this->assertSame($stream, (new Response(200, [], $stream, $this->request()))->getBody());
    }

    public function test_attempt_and_request_come_from_the_request_the_transport_received(): void
    {
        $request = $this->request(2);
        $response = new Response(200, [], ResourceStream::fromString(''), $request);

        $this->assertSame(2, $response->attempt());
        $this->assertSame($request, $response->request);
    }

    public function test_with_duration_and_with_request_return_copies(): void
    {
        $response = $this->response(200, 'x', ['A' => '1']);
        $other = $this->request(3);

        $timed = $response->withDurationMs(12.5);
        $rerequested = $timed->withRequest($other);

        $this->assertSame(0.0, $response->durationMs);
        $this->assertSame(12.5, $timed->durationMs);
        $this->assertSame(12.5, $rerequested->durationMs);
        $this->assertSame($other, $rerequested->request);
        $this->assertSame(['A' => ['1']], $rerequested->headers());
        $this->assertSame('x', $rerequested->body());
    }
}
