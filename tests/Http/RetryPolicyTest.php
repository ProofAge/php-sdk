<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProofAge\Sdk\Exceptions\TransportException;
use ProofAge\Sdk\Http\Request;
use ProofAge\Sdk\Http\Response;
use ProofAge\Sdk\Http\RetryPolicy;
use ProofAge\Sdk\Stream\ResourceStream;

class RetryPolicyTest extends TestCase
{
    private function request(string $method = 'GET'): Request
    {
        return new Request($method, 'https://api.test.com/v1/workspace', '/v1/workspace', [], null, RetryPolicy::download(), 30);
    }

    /** @param array<string, string> $headers */
    private function response(int $status, array $headers = []): Response
    {
        return new Response($status, $headers, ResourceStream::fromString(''), $this->request());
    }

    public function test_interactive_defaults_match_the_laravel_client(): void
    {
        $policy = RetryPolicy::interactive();

        $this->assertSame(3, $policy->maxAttempts);
        $this->assertSame(1000, $policy->delayMs);
    }

    public function test_download_defaults_to_a_single_attempt(): void
    {
        $policy = RetryPolicy::download();

        $this->assertSame(1, $policy->maxAttempts);
        $this->assertSame(1000, $policy->delayMs);
    }

    public function test_attempts_are_clamped_to_at_least_one(): void
    {
        $this->assertSame(1, RetryPolicy::interactive(0)->maxAttempts);
        $this->assertSame(1, RetryPolicy::download(-2, 5)->maxAttempts);
    }

    public function test_both_policies_retry_a_transport_failure_of_a_get(): void
    {
        $refused = new TransportException('Connection refused', 7, requestMayHaveBeenSent: false);
        $timedOut = new TransportException('Operation timed out', 28);

        $this->assertTrue(RetryPolicy::interactive()->shouldRetry($this->request(), null, $refused));
        $this->assertTrue(RetryPolicy::interactive()->shouldRetry($this->request(), null, $timedOut));
        $this->assertTrue(RetryPolicy::download()->shouldRetry($this->request(), null, $refused));
        $this->assertTrue(RetryPolicy::download()->shouldRetry($this->request(), null, $timedOut));
    }

    public function test_a_post_is_retried_after_a_transport_failure_only_when_it_was_never_sent(): void
    {
        $policy = RetryPolicy::interactive();

        $this->assertTrue($policy->shouldRetry($this->request('POST'), null, new TransportException('Connection refused', 7, requestMayHaveBeenSent: false)));
        $this->assertFalse($policy->shouldRetry($this->request('POST'), null, new TransportException('Operation timed out', 28)), 'The server may already have acted on it.');
        $this->assertFalse($policy->shouldRetry($this->request('PATCH'), null, new TransportException('Empty reply from server', 52)));
    }

    /** @return iterable<string, array{int, array<string, string>, bool}> */
    public static function postStatuses(): iterable
    {
        yield '200' => [200, [], false];
        yield '301' => [301, [], false];
        yield '422' => [422, [], false];
        yield '429 without Retry-After' => [429, [], false];
        yield '429 with Retry-After' => [429, ['Retry-After' => '1'], true];
        yield '500' => [500, [], false];
        yield '502' => [502, [], false];
        yield '503' => [503, ['Retry-After' => '5'], false];
    }

    /** @param array<string, string> $headers */
    #[DataProvider('postStatuses')]
    public function test_a_post_is_retried_only_on_a_rate_limit_that_says_when(int $status, array $headers, bool $expected): void
    {
        $this->assertSame($expected, RetryPolicy::interactive()->shouldRetry($this->request('POST'), $this->response($status, $headers), null));
    }

    public function test_idempotent_methods(): void
    {
        foreach (['GET', 'get', 'HEAD', 'OPTIONS', 'PUT', 'DELETE'] as $method) {
            $this->assertTrue(RetryPolicy::isIdempotent($method), $method);
        }

        $this->assertFalse(RetryPolicy::isIdempotent('POST'));
        $this->assertFalse(RetryPolicy::isIdempotent('PATCH'));
    }

    /** @return iterable<string, array{int, bool}> */
    public static function interactiveStatuses(): iterable
    {
        // ProofAgeClient::newHttpRequest(): 4xx only when 429; everything else non-2xx falls through to true.
        yield '200' => [200, false];
        yield '204' => [204, false];
        yield '301' => [301, true];
        yield '400' => [400, false];
        yield '401' => [401, false];
        yield '404' => [404, false];
        yield '422' => [422, false];
        yield '429' => [429, true];
        yield '500' => [500, true];
        yield '502' => [502, true];
        yield '503' => [503, true];
    }

    #[DataProvider('interactiveStatuses')]
    public function test_interactive_decides_per_status(int $status, bool $expected): void
    {
        $this->assertSame($expected, RetryPolicy::interactive()->shouldRetry($this->request(), $this->response($status), null));
    }

    /** @return iterable<string, array{int}> */
    public static function downloadStatuses(): iterable
    {
        foreach ([200, 301, 401, 429, 500, 503] as $status) {
            yield (string) $status => [$status];
        }
    }

    #[DataProvider('downloadStatuses')]
    public function test_download_never_retries_an_http_status(int $status): void
    {
        $this->assertFalse(RetryPolicy::download(5)->shouldRetry($this->request(), $this->response($status), null));
    }

    public function test_a_custom_decider_is_called_with_request_response_and_error(): void
    {
        $seen = [];
        $policy = new RetryPolicy(2, 0, function (Request $request, ?Response $response, ?TransportException $error) use (&$seen): bool {
            $seen[] = [$request, $response, $error];

            return true;
        });

        $request = $this->request();
        $response = $this->response(500);

        $this->assertTrue($policy->shouldRetry($request, $response, null));
        $this->assertSame([[$request, $response, null]], $seen);
    }
}
