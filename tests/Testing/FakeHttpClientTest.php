<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Tests\Testing;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use ProofAge\Sdk\Exceptions\TransportException;
use ProofAge\Sdk\Http\HttpClient;
use ProofAge\Sdk\Http\Request;
use ProofAge\Sdk\Http\Response;
use ProofAge\Sdk\Http\RetryPolicy;
use ProofAge\Sdk\Testing\FakeHttpClient;

class FakeHttpClientTest extends TestCase
{
    private function request(string $url, string $method = 'GET', ?string $sink = null): Request
    {
        return new Request($method, $url, '/v1/x', ['X-API-Key' => 'k'], null, RetryPolicy::interactive(), 30, sink: $sink);
    }

    public function test_it_is_an_http_client(): void
    {
        $this->assertInstanceOf(HttpClient::class, new FakeHttpClient);
    }

    public function test_json_and_raw_build_responses(): void
    {
        $json = FakeHttpClient::json(['id' => 'ws_1'], 201, ['X-Extra' => 'y']);
        $raw = FakeHttpClient::raw('bytes', 200, ['Content-Type' => 'image/jpeg']);

        $this->assertSame(201, $json->status());
        $this->assertSame(['id' => 'ws_1'], $json->json());
        $this->assertSame('application/json', $json->header('Content-Type'));
        $this->assertSame('y', $json->header('x-extra'));

        $this->assertSame('bytes', $raw->body());
        $this->assertSame('image/jpeg', $raw->header('content-type'));
    }

    public function test_routes_match_url_patterns_with_wildcards_in_insertion_order(): void
    {
        $fake = new FakeHttpClient([
            'api.test.com/v1/workspace' => FakeHttpClient::json(['route' => 'exact']),
            'api.test.com/v1/verifications/*' => FakeHttpClient::json(['route' => 'wild']),
            '*' => FakeHttpClient::json(['route' => 'any']),
        ]);

        $this->assertSame('exact', $fake->send($this->request('https://api.test.com/v1/workspace'))->json()['route']);
        $this->assertSame('wild', $fake->send($this->request('https://api.test.com/v1/verifications/ver_1/document'))->json()['route']);
        $this->assertSame('any', $fake->send($this->request('https://elsewhere.test/'))->json()['route']);
    }

    public function test_an_exact_pattern_does_not_match_a_longer_path(): void
    {
        $fake = new FakeHttpClient(['api.test.com/v1/workspace' => FakeHttpClient::json([])]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('No fake response for GET https://api.test.com/v1/workspace/extra');

        $fake->send($this->request('https://api.test.com/v1/workspace/extra'));
    }

    public function test_the_returned_response_carries_the_request_the_fake_received(): void
    {
        $fake = new FakeHttpClient(['*' => FakeHttpClient::json([])]);
        $request = $this->request('https://api.test.com/v1/workspace');

        $response = $fake->send($request);

        $this->assertSame($request, $response->request);
        $this->assertSame([$request], $fake->sent());
    }

    public function test_a_callable_route_receives_the_request(): void
    {
        $fake = (new FakeHttpClient)->on('*', fn (Request $request) => FakeHttpClient::json(['method' => $request->method]));

        $this->assertSame('POST', $fake->send($this->request('https://x.test/', 'POST'))->json()['method']);
    }

    public function test_a_sequence_answers_in_order_then_runs_out(): void
    {
        $fake = new FakeHttpClient(['*' => [
            FakeHttpClient::json(['n' => 1], 429),
            fn () => FakeHttpClient::json(['n' => 2]),
        ]]);

        $this->assertSame(429, $fake->send($this->request('https://x.test/'))->status());
        $this->assertSame(2, $fake->send($this->request('https://x.test/'))->json()['n']);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('No fake response for GET https://x.test/');

        $fake->send($this->request('https://x.test/'));
    }

    public function test_failed_connection_throws_a_transport_exception_and_still_counts_as_sent(): void
    {
        $fake = new FakeHttpClient(['*' => FakeHttpClient::failedConnection('Connection timed out')]);

        try {
            $fake->send($this->request('https://x.test/'));
            $this->fail('Expected a TransportException.');
        } catch (TransportException $e) {
            $this->assertSame('Connection timed out', $e->getMessage());
        }

        $fake->assertSentCount(1);
    }

    public function test_a_sink_receives_the_body_and_the_response_streams_from_it(): void
    {
        $path = sys_get_temp_dir().'/proofage-fake-sink-'.uniqid().'.jpg';
        $fake = new FakeHttpClient(['*' => FakeHttpClient::raw('binary-image-bytes')]);

        $response = $fake->send($this->request('https://x.test/', sink: $path));

        $this->assertSame('binary-image-bytes', file_get_contents($path));
        $this->assertSame('binary-image-bytes', (string) $response->getBody());
        $this->assertSame($path, $response->getBody()->getMetadata('uri'));

        unlink($path);
    }

    public function test_assertions(): void
    {
        $fake = new FakeHttpClient(['*' => FakeHttpClient::json([])]);
        $fake->assertNothingSent();

        $fake->send($this->request('https://x.test/a'));
        $fake->send($this->request('https://x.test/b', 'POST'));

        $fake->assertSentCount(2);
        $fake->assertSent(fn (Request $request) => $request->method === 'POST' && str_ends_with($request->url, '/b'));

        try {
            $fake->assertSent(fn (Request $request) => $request->method === 'DELETE');
            $this->fail('assertSent() must fail when no request matches.');
        } catch (AssertionFailedError) {
        }

        try {
            $fake->assertSentCount(3);
            $this->fail('assertSentCount() must fail on a wrong count.');
        } catch (AssertionFailedError) {
        }

        try {
            $fake->assertNothingSent();
            $this->fail('assertNothingSent() must fail once something was sent.');
        } catch (AssertionFailedError) {
        }
    }

    public function test_a_response_object_is_reusable_across_hits(): void
    {
        $fake = new FakeHttpClient(['*' => FakeHttpClient::json(['ok' => true])]);

        $first = $fake->send($this->request('https://x.test/'));
        $second = $fake->send($this->request('https://x.test/'));

        $this->assertInstanceOf(Response::class, $first);
        $this->assertSame(['ok' => true], $first->json());
        $this->assertSame(['ok' => true], $second->json());
    }
}
