<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Middleware;

use ProofAge\Sdk\Exceptions\TransportException;
use ProofAge\Sdk\Http\Request;
use ProofAge\Sdk\Http\Response;
use ProofAge\Sdk\Http\RetryPolicy;

/**
 * The outermost layer: drives attempts per Request::$retryPolicy, so every layer below
 * it - user middleware, Sign, the transport - runs once per attempt and sees the attempt
 * number on the request.
 *
 * One more rule sits outside the policy. The signature covers whole seconds, so two
 * byte-identical non-GET requests signed in the same second share one, and the API answers the
 * second with 401 REPLAYED_REQUEST from its middleware, before anything was processed. That is
 * resent once, whatever the method and whatever the policy says, after the clock has reached the
 * next whole second, so SignMiddleware signs it afresh with a new X-Timestamp and the same
 * headers otherwise (the Idempotency-Key included). The resend does not count against
 * $policy->maxAttempts; a second REPLAYED_REQUEST is returned for Client to throw.
 */
final class RetryMiddleware
{
    /** @var \Closure(int): void */
    private readonly \Closure $sleep;

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    /**
     * @param  callable(int): void|null  $sleep  receives microseconds, the unit of usleep() and of
     *                                           Laravel's Sleep::usleep(), so a host framework can
     *                                           fake the wait; defaults to usleep() itself.
     * @param  callable(): int|null  $clock  Unix seconds, the same clock SignMiddleware reads; defaults to time()
     */
    public function __construct(?callable $sleep = null, ?callable $clock = null)
    {
        $this->sleep = $sleep === null ? usleep(...) : \Closure::fromCallable($sleep);
        $this->clock = $clock === null ? time(...) : \Closure::fromCallable($clock);
    }

    /**
     * @param  callable(Request): Response  $next
     *
     * @throws TransportException the last one, once the policy gives up, or at once when
     *                            the transport marked it not retryable (a malformed URL)
     */
    public function handle(Request $request, callable $next): Response
    {
        $policy = $request->retryPolicy;

        $replayResent = false;

        for ($attempt = 1; ; $attempt++) {
            $current = $request->withAttempt($attempt + (int) $replayResent);

            try {
                $response = $next($current);
            } catch (TransportException $error) {
                if ($error->isRetryable() && $attempt < $policy->maxAttempts && $policy->shouldRetry($current, null, $error)) {
                    $this->wait($policy, null);

                    continue;
                }

                throw $error;
            }

            if (! $replayResent && self::isReplay($current, $response)) {
                $replayResent = true;
                $this->waitForNextSecond($response);
                $attempt--;

                continue;
            }

            if ($response->successful() || $attempt >= $policy->maxAttempts || ! $policy->shouldRetry($current, $response, null)) {
                return $response;
            }

            $this->wait($policy, $response);
        }
    }

    /** The API verifies a timestamped request once, except GET and HEAD. */
    private static function isReplay(Request $request, Response $response): bool
    {
        return $response->status() === 401
            && ! in_array($request->method, ['GET', 'HEAD'], true)
            && $response->json('error.code') === 'REPLAYED_REQUEST';
    }

    /** Until the clock is at least one second past the timestamp the refused request was signed with. */
    private function waitForNextSecond(Response $response): void
    {
        $signedAt = $response->request->header('X-Timestamp');
        $seconds = $signedAt !== null && ctype_digit($signedAt) ? max(0, (int) $signedAt + 1 - ($this->clock)()) : 1;

        ($this->sleep)($seconds * 1_000_000);
    }

    private function wait(RetryPolicy $policy, ?Response $response): void
    {
        ($this->sleep)($policy->waitMs($response) * 1000);
    }
}
