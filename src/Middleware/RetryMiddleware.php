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
 */
final class RetryMiddleware
{
    /** @var \Closure(int): void */
    private readonly \Closure $sleep;

    /**
     * @param  callable(int): void|null  $sleep  receives microseconds, the unit of usleep() and of
     *                                           Laravel's Sleep::usleep(), so a host framework can
     *                                           fake the wait; defaults to usleep() itself.
     */
    public function __construct(?callable $sleep = null)
    {
        $this->sleep = $sleep === null ? usleep(...) : \Closure::fromCallable($sleep);
    }

    /**
     * @param  callable(Request): Response  $next
     *
     * @throws TransportException the last one, once the policy gives up
     */
    public function handle(Request $request, callable $next): Response
    {
        $policy = $request->retryPolicy;

        for ($attempt = 1; ; $attempt++) {
            $current = $request->withAttempt($attempt);

            try {
                $response = $next($current);
            } catch (TransportException $error) {
                if ($attempt < $policy->maxAttempts && $policy->shouldRetry($current, null, $error)) {
                    $this->wait($policy);

                    continue;
                }

                throw $error;
            }

            if ($response->successful() || $attempt >= $policy->maxAttempts || ! $policy->shouldRetry($current, $response, null)) {
                return $response;
            }

            $this->wait($policy);
        }
    }

    private function wait(RetryPolicy $policy): void
    {
        ($this->sleep)($policy->delayMs * 1000);
    }
}
