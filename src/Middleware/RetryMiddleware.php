<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Middleware;

use ProofAge\Sdk\Exceptions\TransportException;
use ProofAge\Sdk\Http\Clock;
use ProofAge\Sdk\Http\Request;
use ProofAge\Sdk\Http\Response;
use ProofAge\Sdk\Http\RetryPolicy;

/**
 * The outermost layer: drives attempts per Request::$retryPolicy, so every layer below
 * it - user middleware, Sign, the transport - runs once per attempt and sees the attempt
 * number on the request.
 *
 * Three rules sit outside the policy, all because of the timestamped signature:
 *
 * - Same second. The signature covers whole seconds, and the API accepts a timestamped
 *   non-GET request once. Before any re-attempt of such a request (a retry or either resend
 *   below) the wait lasts until the clock is past the timestamp of the previous attempt;
 *   otherwise the re-attempt would sign the same bytes and meet 401 REPLAYED_REQUEST.
 * - 401 REPLAYED_REQUEST on a non-GET/HEAD request: two identical requests were signed in
 *   the same second and this one was refused before anything ran. Resent once.
 * - 401 TIMESTAMP_OUT_OF_RANGE, any method: the host clock is more than 300 seconds off. When
 *   the response has a parseable Date header, the shared Clock takes the server's time (this and
 *   every later request of the client are stamped with it) and the request is resent once.
 *   Without a Date header it is returned for Client to throw.
 *
 * Each of the two resends happens at most once per call, keeps every header (the
 * Idempotency-Key included), is signed afresh by SignMiddleware and does not count against
 * $policy->maxAttempts. A second refusal is returned for Client to throw.
 */
final class RetryMiddleware
{
    /** @var \Closure(int): void */
    private readonly \Closure $sleep;

    private readonly Clock $clock;

    /**
     * @param  callable(int): void|null  $sleep  receives microseconds, the unit of usleep() and of
     *                                           Laravel's Sleep::usleep(), so a host framework can
     *                                           fake the wait; defaults to usleep() itself.
     * @param  Clock|callable(): int|null  $clock  the clock SignMiddleware stamps with (Client shares one);
     *                                             a callable gives Unix seconds and defaults to time()
     */
    public function __construct(?callable $sleep = null, Clock|callable|null $clock = null)
    {
        $this->sleep = $sleep === null ? usleep(...) : \Closure::fromCallable($sleep);
        $this->clock = Clock::from($clock);
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
        $skewResent = false;
        $once = ! in_array($request->method, ['GET', 'HEAD'], true);

        for ($attempt = 1; ; $attempt++) {
            $current = $request->withAttempt($attempt + (int) $replayResent + (int) $skewResent);

            try {
                $response = $next($current);
            } catch (TransportException $error) {
                if ($error->isRetryable() && $attempt < $policy->maxAttempts && $policy->shouldRetry($current, null, $error)) {
                    $this->wait($policy, null, $once, $this->clock->lastStamp());

                    continue;
                }

                throw $error;
            }

            $stamp = self::stampOf($response) ?? $this->clock->lastStamp();

            if (! $skewResent && self::isOutOfRange($response) && $this->clock->adjustToServerDate($response->header('Date'))) {
                $skewResent = true;
                $attempt--;

                continue;
            }

            if (! $replayResent && $once && self::isReplay($response)) {
                $replayResent = true;
                $this->wait($policy, null, true, $stamp, pause: false);
                $attempt--;

                continue;
            }

            if ($response->successful() || $attempt >= $policy->maxAttempts || ! $policy->shouldRetry($current, $response, null)) {
                return $response;
            }

            $this->wait($policy, $response, $once, $stamp);
        }
    }

    private static function stampOf(Response $response): ?int
    {
        $stamp = $response->request->header('X-Timestamp');

        return $stamp !== null && ctype_digit($stamp) ? (int) $stamp : null;
    }

    private static function isReplay(Response $response): bool
    {
        return $response->status() === 401 && $response->json('error.code') === 'REPLAYED_REQUEST';
    }

    private static function isOutOfRange(Response $response): bool
    {
        return $response->status() === 401 && $response->json('error.code') === 'TIMESTAMP_OUT_OF_RANGE';
    }

    /**
     * One sleep before a re-attempt: the policy's delay (Retry-After for an in-progress 409),
     * or, for a non-GET/HEAD request, as long as it takes the clock to get past the timestamp of
     * the attempt before, if that is longer. $pause false drops the delay and keeps the second.
     */
    private function wait(RetryPolicy $policy, ?Response $response, bool $nextSecond, ?int $previousStamp, bool $pause = true): void
    {
        $microseconds = $pause ? $policy->waitMs($response) * 1000 : 0;

        if ($nextSecond && $previousStamp !== null) {
            $microseconds = max($microseconds, max(0, $previousStamp + 1 - $this->clock->now()) * 1_000_000);
        }

        ($this->sleep)($microseconds);
    }
}
