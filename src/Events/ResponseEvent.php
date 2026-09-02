<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Events;

use ProofAge\Sdk\Http\Response;

/**
 * The response coming back up through the Sign layer: status, headers, size, timing.
 * There is no body accessor - document() bodies carry names and dates of birth and
 * downloadMedia() bodies are the photos. raw() returns the real Response.
 */
final class ResponseEvent
{
    public function __construct(private readonly Response $response) {}

    public function status(): int
    {
        return $this->response->status;
    }

    /** @return array<string, list<string>> */
    public function headers(): array
    {
        return $this->response->headers;
    }

    public function durationMs(): float
    {
        return $this->response->durationMs;
    }

    public function attempt(): int
    {
        return $this->response->attempt();
    }

    public function contentType(): ?string
    {
        return $this->response->header('Content-Type');
    }

    /** The Content-Length header, or the buffered size when the body was not streamed. */
    public function contentLength(): ?int
    {
        $header = $this->response->header('Content-Length');

        if ($header !== null && is_numeric($header)) {
            return (int) $header;
        }

        if ($this->response->request->stream) {
            return null;
        }

        return $this->response->getBody()->getSize();
    }

    public function request(): RequestEvent
    {
        return new RequestEvent($this->response->request);
    }

    public function raw(): Response
    {
        return $this->response;
    }
}
