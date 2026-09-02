<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Events;

use ProofAge\Sdk\Http\Request;

/**
 * A redacted, read-only view of the signed request as it goes down to the transport.
 * raw() returns the real Request with the key, the signature and the bytes; calling it
 * is the deliberate act.
 */
final class RequestEvent
{
    public function __construct(private readonly Request $request) {}

    public function method(): string
    {
        return $this->request->method;
    }

    public function url(): string
    {
        return $this->request->url;
    }

    public function path(): string
    {
        return $this->request->path;
    }

    public function attempt(): int
    {
        return $this->request->attempt;
    }

    /** @return array<string, string> X-API-Key and X-HMAC-Signature masked */
    public function headers(): array
    {
        return Redactor::headers($this->request->headers);
    }

    /**
     * @return array{kind: 'none'}|array{kind: 'json', bytes: int, sha256: string}|array{kind: 'multipart', fields: array<string, mixed>, files: list<array{name: string, filename: string, bytes: int, sha256: string}>}
     */
    public function body(): array
    {
        return Redactor::body($this->request->body);
    }

    public function raw(): Request
    {
        return $this->request;
    }
}
