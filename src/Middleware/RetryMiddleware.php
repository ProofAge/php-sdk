<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Middleware;

use ProofAge\Sdk\Exceptions\TransportException;
use ProofAge\Sdk\Http\Request;
use ProofAge\Sdk\Http\Response;

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
     * @param  callable(int): void|null  $sleep  milliseconds; defaults to usleep(). Tests inject a recorder.
     */
    public function __construct(?callable $sleep = null)
    {
        $this->sleep = $sleep === null
            ? static function (int $ms): void {
                usleep($ms * 1000);
            }
        : \Closure::fromCallable($sleep);
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
                    ($this->sleep)($policy->delayMs);

                    continue;
                }

                throw $error;
            }

            if ($response->successful() || $attempt >= $policy->maxAttempts || ! $policy->shouldRetry($current, $response, null)) {
                return $response;
            }

            ($this->sleep)($policy->delayMs);
        }
    }
}
