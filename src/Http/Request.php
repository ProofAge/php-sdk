<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Http;

use ProofAge\Sdk\Events\Redactor;
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
     *
     * @throws \InvalidArgumentException for a header name that is not a token or a value
     *                                   containing CR, LF or NUL (see withHeader())
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

        foreach ($headers as $name => $value) {
            self::assertHeader((string) $name, $value);
        }
    }

    /**
     * Replaces the header case-insensitively.
     *
     * A transport writes `Name: value` lines, so a line break inside a value ends the
     * line and whatever follows goes out as another header. A middleware that copies an
     * untrusted string into a header could therefore inject headers; the value is
     * rejected here instead of being sent verbatim.
     *
     * @throws \InvalidArgumentException for a name that is not an HTTP token or a value
     *                                   containing CR, LF or NUL
     */
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

    /**
     * What print_r() and var_dump() show: X-API-Key and X-HMAC-Signature masked as the
     * events mask them, and the body reduced to sizes and hashes. A request reaches a dump
     * through every SDK exception's trace and through getResponse()->request, so this is
     * where the plaintext key and an uploaded selfie would otherwise leak into a log.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'method' => $this->method,
            'url' => $this->url,
            'path' => $this->path,
            'headers' => Redactor::headers($this->headers),
            'body' => $this->body,
            'retryPolicy' => $this->retryPolicy,
            'timeout' => $this->timeout,
            'sink' => $this->sink,
            'stream' => $this->stream,
            'attempt' => $this->attempt,
        ];
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

    private static function assertHeader(string $name, string $value): void
    {
        // RFC 9110 token: the name is one or more tchar, so a line break, a space or a
        // colon cannot end the name early and start something else.
        if (preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/', $name) !== 1) {
            throw new \InvalidArgumentException('Invalid header name '.json_encode($name, JSON_UNESCAPED_SLASHES).': must be an HTTP token');
        }

        if (preg_match('/[\r\n\0]/', $value) === 1) {
            throw new \InvalidArgumentException("Header {$name} must not contain CR, LF or NUL: the value would end the header line and inject another");
        }
    }
}
