<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Tests\Middleware;

use PHPUnit\Framework\TestCase;
use ProofAge\Sdk\Exceptions\TransportException;
use ProofAge\Sdk\Http\Request;
use ProofAge\Sdk\Http\Response;
use ProofAge\Sdk\Http\RetryPolicy;
use ProofAge\Sdk\Middleware\RetryMiddleware;
use ProofAge\Sdk\Testing\FakeHttpClient;

class RetryMiddlewareTest extends TestCase
{
    /** @var list<int> */
    private array $sleeps = [];

    private function middleware(): RetryMiddleware
    {
        return new RetryMiddleware(function (int $ms): void {
            $this->sleeps[] = $ms;
        });
    }

    private function request(RetryPolicy $policy): Request
    {
        return new Request('GET', 'https://api.test.com/v1/workspace', '/v1/workspace', [], null, $policy, 30);
    }

    /** @param list<Response|callable> $sequence */
    private function sendThrough(RetryPolicy $policy, array $sequence, ?FakeHttpClient &$fake = null): Response
    {
        $fake = new FakeHttpClient(['*' => $sequence]);

        return $this->middleware()->handle($this->request($policy), static fn (Request $request): Response => $fake->send($request));
    }

    public function test_interactive_retries_a_429_once_then_returns_the_200(): void
    {
        $response = $this->sendThrough(RetryPolicy::interactive(3, 1000), [FakeHttpClient::json([], 429), FakeHttpClient::json(['ok' => true])], $fake);

        $this->assertSame(200, $response->status());
        $this->assertSame(2, $response->attempt());
        $fake->assertSentCount(2);
        $this->assertSame([1000], $this->sleeps);
    }

    public function test_interactive_does_not_retry_a_401(): void
    {
        $response = $this->sendThrough(RetryPolicy::interactive(3, 1000), [FakeHttpClient::json([], 401), FakeHttpClient::json([])], $fake);

        $this->assertSame(401, $response->status());
        $fake->assertSentCount(1);
        $this->assertSame([], $this->sleeps);
    }

    public function test_interactive_retries_5xx_until_a_success(): void
    {
        $response = $this->sendThrough(RetryPolicy::interactive(3, 250), [FakeHttpClient::json([], 500), FakeHttpClient::json([], 500), FakeHttpClient::json([])], $fake);

        $this->assertSame(200, $response->status());
        $this->assertSame(3, $response->attempt());
        $fake->assertSentCount(3);
        $this->assertSame([250, 250], $this->sleeps);
    }

    public function test_the_last_response_is_returned_when_attempts_run_out(): void
    {
        $response = $this->sendThrough(RetryPolicy::interactive(3, 10), [FakeHttpClient::json(['n' => 1], 500), FakeHttpClient::json(['n' => 2], 503), FakeHttpClient::json(['n' => 3], 502), FakeHttpClient::json([])], $fake);

        $this->assertSame(502, $response->status());
        $this->assertSame(3, $response->json()['n']);
        $fake->assertSentCount(3);
        $this->assertSame([10, 10], $this->sleeps);
    }

    public function test_transport_failures_are_retried_and_the_last_one_is_thrown(): void
    {
        $sequence = [
            FakeHttpClient::failedConnection('first'),
            FakeHttpClient::failedConnection('second'),
            FakeHttpClient::failedConnection('third'),
        ];

        try {
            $this->sendThrough(RetryPolicy::interactive(3, 100), $sequence, $fake);
            $this->fail('Expected the last TransportException.');
        } catch (TransportException $e) {
            $this->assertSame('third', $e->getMessage());
        }

        $fake->assertSentCount(3);
        $this->assertSame([100, 100], $this->sleeps);
    }

    public function test_a_single_attempt_policy_never_retries(): void
    {
        $response = $this->sendThrough(RetryPolicy::interactive(1, 1000), [FakeHttpClient::json([], 429), FakeHttpClient::json([])], $fake);

        $this->assertSame(429, $response->status());
        $fake->assertSentCount(1);
        $this->assertSame([], $this->sleeps);
    }

    public function test_download_never_retries_a_429(): void
    {
        $response = $this->sendThrough(RetryPolicy::download(3, 1000), [FakeHttpClient::json([], 429), FakeHttpClient::raw('bytes')], $fake);

        $this->assertSame(429, $response->status());
        $fake->assertSentCount(1);
        $this->assertSame([], $this->sleeps);
    }

    public function test_download_retries_a_transport_failure_when_attempts_allow(): void
    {
        $response = $this->sendThrough(RetryPolicy::download(2, 0), [FakeHttpClient::failedConnection('Connection timed out'), FakeHttpClient::raw('bytes')], $fake);

        $this->assertSame('bytes', $response->body());
        $this->assertSame(2, $response->attempt());
        $fake->assertSentCount(2);
        $this->assertSame([0], $this->sleeps);
    }

    public function test_download_with_one_attempt_rethrows_a_transport_failure_immediately(): void
    {
        $this->expectException(TransportException::class);

        try {
            $this->sendThrough(RetryPolicy::download(1, 1000), [FakeHttpClient::failedConnection(), FakeHttpClient::raw('bytes')], $fake);
        } finally {
            $fake->assertSentCount(1);
            $this->assertSame([], $this->sleeps);
        }
    }

    public function test_each_attempt_carries_its_number(): void
    {
        $this->sendThrough(RetryPolicy::interactive(3, 0), [FakeHttpClient::json([], 500), FakeHttpClient::json([], 500), FakeHttpClient::json([])], $fake);

        $this->assertSame([1, 2, 3], array_map(static fn (Request $request): int => $request->attempt, $fake->sent()));
    }

    public function test_the_default_sleep_really_sleeps(): void
    {
        $start = hrtime(true);

        (new RetryMiddleware)->handle(
            $this->request(RetryPolicy::interactive(2, 20)),
            static fn (Request $request): Response => FakeHttpClient::json([], 500)->withRequest($request),
        );

        $this->assertGreaterThanOrEqual(20, (hrtime(true) - $start) / 1e6);
    }
}
