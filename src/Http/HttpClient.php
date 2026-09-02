<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Http;

use ProofAge\Sdk\Exceptions\TransportException;

/**
 * One request in, one response out. A transport sends exactly what it is given —
 * signed headers, raw bytes — and never retries, follows redirects or throws on an
 * HTTP status; Client and the middleware pipeline own all of that.
 */
interface HttpClient
{
    /**
     * @throws TransportException on any failure below HTTP (connection, DNS, TLS, timeout)
     */
    public function send(Request $request): Response;
}
