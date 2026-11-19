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
    private function response(int $status, array $headers = [], ?string $errorCode = null): Response
    {
        $body = $errorCode === null ? '' : json_encode(['error' => ['code' => $errorCode, 'message' => 'x']], JSON_THROW_ON_ERROR);

        return new Response($status, $headers, ResourceStream::fromString($body), $this->request());
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

    /** @param array<string, string> $headers */
    #[DataProvider('postStatuses')]
    public function test_a_delete_marked_not_idempotent_is_retried_as_a_post_is(int $status, array $headers, bool $expected): void
    {
        $this->assertSame($expected, RetryPolicy::interactive(idempotent: false)->shouldRetry($this->request('DELETE'), $this->response($status, $headers), null));
    }

    public function test_a_delete_marked_not_idempotent_is_retried_after_a_transport_failure_only_when_it_was_never_sent(): void
    {
        $policy = RetryPolicy::interactive(idempotent: false);

        $this->assertTrue($policy->shouldRetry($this->request('DELETE'), null, new TransportException('Connection refused', 7, requestMayHaveBeenSent: false)));
        $this->assertFalse($policy->shouldRetry($this->request('DELETE'), null, new TransportException('Operation timed out', 28)), 'The server may already have acted on it.');
    }

    public function test_a_delete_goes_by_its_method_unless_told_otherwise(): void
    {
        $this->assertTrue(RetryPolicy::interactive()->shouldRetry($this->request('DELETE'), $this->response(503), null));
        $this->assertTrue(RetryPolicy::interactive(idempotent: true)->shouldRetry($this->request('POST'), $this->response(503), null));
    }

    public function test_a_post_with_an_idempotency_key_is_retried_like_a_get(): void
    {
        $keyed = $this->request('POST')->withHeader('Idempotency-Key', 'order-1');
        $policy = RetryPolicy::interactive();

        $this->assertTrue($policy->shouldRetry($keyed, null, new TransportException('Operation timed out', 28)));
        $this->assertTrue($policy->shouldRetry($keyed, $this->response(500), null));
        $this->assertTrue($policy->shouldRetry($keyed, $this->response(429), null), 'A 429 without Retry-After is retried too.');
        $this->assertFalse($policy->shouldRetry($keyed, $this->response(429, [], 'REAUTH_RATE_LIMITED'), null), 'It does not clear within seconds.');
        $this->assertTrue($policy->shouldRetry($keyed, $this->response(429, [], 'RATE_LIMIT'), null));
        $this->assertFalse($policy->shouldRetry($keyed, $this->response(422), null));
        $this->assertFalse($policy->shouldRetry($keyed, $this->response(200), null));
    }

    public function test_a_post_without_a_key_keeps_the_post_rules(): void
    {
        $policy = RetryPolicy::interactive();
        $plain = $this->request('POST');

        $this->assertFalse($policy->shouldRetry($plain, null, new TransportException('Operation timed out', 28)));
        $this->assertFalse($policy->shouldRetry($plain, $this->response(500), null));
        $this->assertFalse($policy->shouldRetry($plain, $this->response(429), null));
        $this->assertFalse($policy->shouldRetry($plain, $this->response(409, [], 'IDEMPOTENCY_KEY_IN_PROGRESS'), null));
    }

    public function test_a_409_is_retried_only_while_the_first_request_with_the_key_is_running(): void
    {
        $keyed = $this->request('POST')->withHeader('Idempotency-Key', 'order-1');
        $policy = RetryPolicy::interactive();

        $this->assertTrue($policy->shouldRetry($keyed, $this->response(409, ['Retry-After' => '1'], 'IDEMPOTENCY_KEY_IN_PROGRESS'), null));
        $this->assertFalse($policy->shouldRetry($keyed, $this->response(409, [], 'IDEMPOTENCY_KEY_REUSED'), null));
        $this->assertFalse($policy->shouldRetry($keyed, $this->response(409), null));
    }

    public function test_marking_a_call_not_idempotent_wins_over_the_key(): void
    {
        $keyed = $this->request('DELETE')->withHeader('Idempotency-Key', 'k');

        $this->assertFalse(RetryPolicy::interactive(3, 1000, idempotent: false)->shouldRetry($keyed, $this->response(500), null));
    }

    public function test_a_retry_after_on_an_in_progress_409_is_waited_out_up_to_ten_seconds(): void
    {
        $policy = RetryPolicy::interactive(3, 1000);
        $inProgress = static fn (string $retryAfter): Response => new Response(409, ['Retry-After' => $retryAfter], ResourceStream::fromString('{"error":{"code":"IDEMPOTENCY_KEY_IN_PROGRESS"}}'), new Request('POST', 'https://x', '/v1/verifications', [], null, RetryPolicy::download(), 30));

        $this->assertSame(1000, $policy->waitMs(null));
        $this->assertSame(1000, $policy->waitMs($inProgress('1')));
        $this->assertSame(3000, $policy->waitMs($inProgress('3')));
        $this->assertSame(10000, $policy->waitMs($inProgress('600')));
        $this->assertSame(1000, $policy->waitMs($inProgress('soon')));
        $this->assertSame(1000, $policy->waitMs($this->response(503, ['Retry-After' => '5'])), 'Only the in-progress 409 waits out Retry-After.');
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
