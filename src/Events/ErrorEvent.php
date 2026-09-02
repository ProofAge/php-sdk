<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Events;

use ProofAge\Sdk\Exceptions\TransportException;
use ProofAge\Sdk\Http\Request;

/**
 * A transport failure (connection refused, DNS, TLS, timeout) crossing the Sign layer.
 * HTTP error statuses are responses and arrive through ResponseEvent instead.
 */
final class ErrorEvent
{
    public function __construct(
        private readonly TransportException $exception,
        private readonly RequestEvent $request,
        private readonly float $durationMs,
    ) {}

    public function exception(): TransportException
    {
        return $this->exception;
    }

    public function durationMs(): float
    {
        return $this->durationMs;
    }

    public function attempt(): int
    {
        return $this->request->attempt();
    }

    public function request(): RequestEvent
    {
        return $this->request;
    }

    public function raw(): Request
    {
        return $this->request->raw();
    }
}
