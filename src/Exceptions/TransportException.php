<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Exceptions;

/**
 * A failure below HTTP: connection refused, DNS, TLS, timeout. Never carries a Response.
 *
 * getCode() is the cURL errno for CurlHttpClient and 0 otherwise; getPrevious() is the
 * transport's own exception where one exists (PSR-18, Illuminate).
 *
 * A transport that knows a failure is deterministic and local - a malformed URL, an
 * unsupported scheme - constructs it with $retryable false, and RetryMiddleware throws it
 * at once instead of spending every attempt and every delay on the same answer.
 */
class TransportException extends ProofAgeException
{
    public function __construct(
        string $message,
        int $code = 0,
        ?\Throwable $previous = null,
        private readonly bool $retryable = true,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /** Whether another attempt could possibly succeed. */
    public function isRetryable(): bool
    {
        return $this->retryable;
    }
}
