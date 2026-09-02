<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Exceptions;

/**
 * A failure below HTTP: connection refused, DNS, TLS, timeout. Never carries a Response.
 *
 * getCode() is the cURL errno for CurlHttpClient and 0 otherwise; getPrevious() is the
 * transport's own exception where one exists (PSR-18, Illuminate).
 */
class TransportException extends ProofAgeException
{
    public function __construct(string $message, int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
