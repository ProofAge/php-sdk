<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Http;

use ProofAge\Sdk\Http\Body\MultipartBody;
use ProofAge\Sdk\Http\Body\RawBody;

/**
 * Immutable request; every with*() returns a copy.
 *
 * $path is the canonical path the signature covers, `/{version}/{endpoint}[?query]`,
 * set by Client from its config rather than parsed from $url. A middleware that
 * redirects $url to a proxy therefore does not change the signature; one that calls
 * withPath() does.
 */
final class Request
{
    public readonly string $method;

    /**
     * @param  array<string, string>  $headers  name => value, single-valued
     * @param  int  $timeout  seconds
     * @param  string|null  $sink  stream the response body to this path
     * @param  bool  $stream  do not buffer the response body
     */
    public function __construct(
        string $method,
        public readonly string $url,
        public readonly string $path,
        public readonly array $headers,
        public readonly RawBody|MultipartBody|null $body,
        public readonly RetryPolicy $retryPolicy,
        public readonly int $timeout,
        public readonly ?string $sink = null,
        public readonly bool $stream = false,
        public readonly int $attempt = 1,
    ) {
        $this->method = strtoupper($method);
    }

    public function withHeader(string $name, string $value): static
    {
        $headers = $this->headersWithout($name);
        $headers[$name] = $value;

        return new self($this->method, $this->url, $this->path, $headers, $this->body, $this->retryPolicy, $this->timeout, $this->sink, $this->stream, $this->attempt);
    }

    public function withoutHeader(string $name): static
    {
        return new self($this->method, $this->url, $this->path, $this->headersWithout($name), $this->body, $this->retryPolicy, $this->timeout, $this->sink, $this->stream, $this->attempt);
    }

    public function withBody(RawBody|MultipartBody|null $body): static
    {
        return new self($this->method, $this->url, $this->path, $this->headers, $body, $this->retryPolicy, $this->timeout, $this->sink, $this->stream, $this->attempt);
    }

    public function withUrl(string $url): static
    {
        return new self($this->method, $url, $this->path, $this->headers, $this->body, $this->retryPolicy, $this->timeout, $this->sink, $this->stream, $this->attempt);
    }

    public function withPath(string $path): static
    {
        return new self($this->method, $this->url, $path, $this->headers, $this->body, $this->retryPolicy, $this->timeout, $this->sink, $this->stream, $this->attempt);
    }

    public function withAttempt(int $attempt): static
    {
        return new self($this->method, $this->url, $this->path, $this->headers, $this->body, $this->retryPolicy, $this->timeout, $this->sink, $this->stream, $attempt);
    }

    /** Case-insensitive lookup. */
    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    /** @return array<string, string> */
    private function headersWithout(string $name): array
    {
        return array_filter($this->headers, static fn (string $key): bool => strcasecmp($key, $name) !== 0, ARRAY_FILTER_USE_KEY);
    }
}
