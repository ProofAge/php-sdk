<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Http;

use ProofAge\Sdk\Exceptions\TransportException;

/**
 * How many attempts a request gets and what earns another one. Constant delay, no
 * backoff, no jitter; Retry-After gates a POST retry but its value is not waited out.
 */
final class RetryPolicy
{
    /**
     * Methods a repeated request cannot do harm with. POST (create, consent, upload,
     * submit, blocked-face) and PATCH are left out.
     */
    private const IDEMPOTENT_METHODS = ['GET', 'HEAD', 'OPTIONS', 'PUT', 'DELETE', 'TRACE'];

    public const IDEMPOTENCY_KEY_HEADER = 'Idempotency-Key';

    /** The 409 the API answers while the first request with the same Idempotency-Key is still running. */
    private const IN_PROGRESS_CODE = 'IDEMPOTENCY_KEY_IN_PROGRESS';

    /** A 429 that does not clear within seconds, so it is never retried, key or no key. */
    private const NOT_RETRIED_429_CODE = 'REAUTH_RATE_LIMITED';

    /** Longest Retry-After waited out, in seconds. */
    private const MAX_RETRY_AFTER_SECONDS = 10;

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
     * The policy of every JSON and multipart call.
     *
     * An idempotent request (GET) is retried after a transport failure, a 429, or any
     * non-2xx that is not a 4xx (a 3xx or a 5xx).
     *
     * A POST is retried only when a retry cannot make the server act twice: after a
     * transport failure the transport marked as never sent (DNS, connection refused, TLS
     * handshake), or after a 429 that carries Retry-After, which the API's rate limiter
     * sends before the request is handled. A 5xx or a timeout is not retried: the server
     * may already have created the verification, stored the upload or submitted it, and a
     * second attempt would do it again. The caller decides, knowing what it sent.
     *
     * $idempotent overrides what the method says. False gives a call the rules of a POST
     * whatever its method: the erasure DELETE (/verifications/{id}/personal-data) is
     * idempotent by HTTP, but a 5xx or a timeout there leaves the caller not knowing whether
     * the data is gone, and the API answers 409 or 503 with its own Retry-After for the
     * caller to act on, so it is not repeated blindly. Null (the default) goes by the method.
     *
     * A request that carries an Idempotency-Key is idempotent whatever its method: the API
     * replays the first response for the same key and request, so a retry cannot create
     * twice. It then gets the rules of a GET, plus a retry of 409 IDEMPOTENCY_KEY_IN_PROGRESS
     * (the first attempt is still running), which waits out the response's Retry-After.
     * $idempotent = false still wins over the key. A 429 REAUTH_RATE_LIMITED (more than 10
     * face re-verifications of one reference in 24 hours) is not retried: it does not clear
     * within seconds.
     */
    public static function interactive(int $attempts = 3, int $delayMs = 1000, ?bool $idempotent = null): self
    {
        $override = $idempotent;

        return new self($attempts, $delayMs, static function (Request $request, ?Response $response, ?TransportException $error) use ($override): bool {
            $idempotent = $override ?? (self::isIdempotent($request->method) || $request->header(self::IDEMPOTENCY_KEY_HEADER) !== null);

            if ($error !== null) {
                return $idempotent || ! $error->requestMayHaveBeenSent();
            }

            if ($response === null || $response->successful()) {
                return false;
            }

            if ($response->status() === 429) {
                if ($response->header('Retry-After') === null && $response->json('error.code') === self::NOT_RETRIED_429_CODE) {
                    return false;
                }

                return $idempotent || $response->header('Retry-After') !== null;
            }

            if ($response->status() === 409 && $idempotent && $request->header(self::IDEMPOTENCY_KEY_HEADER) !== null) {
                return $response->json('error.code') === self::IN_PROGRESS_CODE;
            }

            if ($response->status() >= 400 && $response->status() < 500) {
                return false;
            }

            return $idempotent;
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

    public static function isIdempotent(string $method): bool
    {
        return in_array(strtoupper($method), self::IDEMPOTENT_METHODS, true);
    }

    /**
     * Milliseconds to wait before the next attempt: the constant delay, or for a 409
     * IDEMPOTENCY_KEY_IN_PROGRESS the response's Retry-After (whole seconds, at most 10) when
     * that is longer. Every other retry keeps the constant delay.
     */
    public function waitMs(?Response $response = null): int
    {
        if ($response === null || $response->status() !== 409 || $response->json('error.code') !== self::IN_PROGRESS_CODE) {
            return $this->delayMs;
        }

        $retryAfter = $response->header('Retry-After');

        if ($retryAfter === null || preg_match('/^\d+$/', trim($retryAfter)) !== 1) {
            return $this->delayMs;
        }

        return max($this->delayMs, min((int) $retryAfter, self::MAX_RETRY_AFTER_SECONDS) * 1000);
    }

    public function shouldRetry(Request $request, ?Response $response, ?TransportException $error): bool
    {
        return ($this->decide)($request, $response, $error);
    }
}
