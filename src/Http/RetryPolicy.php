<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Http;

use ProofAge\Sdk\Exceptions\TransportException;

/**
 * How many attempts a request gets and what earns another one. Constant delay, no
 * backoff, no jitter, Retry-After not honoured: the same behaviour the Laravel client's
 * Http::retry() closures had.
 */
final class RetryPolicy
{
    /** Total attempts, >= 1. */
    public readonly int $maxAttempts;

    /**
     * @param  \Closure(Request, ?Response, ?TransportException): bool  $decide
     */
    public function __construct(
        int $maxAttempts,
        public readonly int $delayMs,
        private readonly \Closure $decide,
    ) {
        $this->maxAttempts = max(1, $maxAttempts);
    }

    /**
     * ProofAgeClient::newHttpRequest(): a transport failure, a 429, or any non-2xx that is
     * not 4xx (so 5xx is retried, as it always was) earns another attempt.
     */
    public static function interactive(int $attempts = 3, int $delayMs = 1000): self
    {
        return new self($attempts, $delayMs, static function (Request $request, ?Response $response, ?TransportException $error): bool {
            if ($error !== null) {
                return true;
            }

            if ($response === null) {
                return false;
            }

            if ($response->status() >= 400 && $response->status() < 500) {
                return $response->status() === 429;
            }

            return ! $response->successful();
        });
    }

    /**
     * ProofAgeClient::newDownloadHttpRequest(): a transport failure only, never an HTTP status.
     *
     * A download runs from a queue whose own backoff owns the wait, so an in-process retry
     * is not free: on 429 it spends the same per-minute budget that just refused us, and any
     * sleep blocks the worker rather than releasing the job. Honouring Retry-After here would
     * block it for longer still. So HTTP statuses are never retried — the caller's queue
     * decides — and only a genuine connection failure is, if the operator raises
     * download_retry_attempts above the default of 1 (no retries at all).
     *
     * The interactive path keeps its 3 quick retries: there a user is waiting and there is
     * no queue to hand the wait to.
     */
    public static function download(int $attempts = 1, int $delayMs = 1000): self
    {
        return new self($attempts, $delayMs, static fn (Request $request, ?Response $response, ?TransportException $error): bool => $error !== null);
    }

    public function shouldRetry(Request $request, ?Response $response, ?TransportException $error): bool
    {
        return ($this->decide)($request, $response, $error);
    }
}
