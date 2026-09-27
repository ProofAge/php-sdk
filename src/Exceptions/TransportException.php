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
 *
 * A transport that knows the request never left - DNS failed, the connection was
 * refused, the TLS handshake failed - constructs it with $requestMayHaveBeenSent false.
 * Only then is a POST retried: after a timeout or a dropped connection the server may
 * already have acted on it, and a second upload or submit would act twice. The default
 * is true, the safe answer for a transport that cannot tell.
 */
class TransportException extends ProofAgeException
{
    public function __construct(
        string $message,
        int $code = 0,
        ?\Throwable $previous = null,
        private readonly bool $retryable = true,
        private readonly bool $requestMayHaveBeenSent = true,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /** Whether another attempt could possibly succeed. */
    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    /**
     * False only when the transport knows no byte of the request reached the server,
     * which is what makes retrying a POST safe.
     */
    public function requestMayHaveBeenSent(): bool
    {
        return $this->requestMayHaveBeenSent;
    }
}
