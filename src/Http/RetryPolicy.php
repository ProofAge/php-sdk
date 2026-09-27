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
     */
    public static function interactive(int $attempts = 3, int $delayMs = 1000): self
    {
        return new self($attempts, $delayMs, static function (Request $request, ?Response $response, ?TransportException $error): bool {
            $idempotent = self::isIdempotent($request->method);

            if ($error !== null) {
                return $idempotent || ! $error->requestMayHaveBeenSent();
            }

            if ($response === null || $response->successful()) {
                return false;
            }

            if ($response->status() === 429) {
                return $idempotent || $response->header('Retry-After') !== null;
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

    public function shouldRetry(Request $request, ?Response $response, ?TransportException $error): bool
    {
        return ($this->decide)($request, $response, $error);
    }
}
