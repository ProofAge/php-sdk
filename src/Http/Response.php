<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Http;

use Psr\Http\Message\StreamInterface;

/**
 * What a transport returns. Mirrors the framework-free half of
 * Illuminate\Http\Client\Response's surface (status(), body(), json(), header(),
 * headers(), successful(), failed(), ok()) so code that only reads a response
 * does not notice the transport underneath.
 */
final class Response
{
    /** @var array<string, list<string>> lower-cased name => values */
    public readonly array $headers;

    /**
     * @param  array<string, string|array<string>>  $headers  values may be a string or any array of strings (PSR-7 style)
     * @param  Request  $request  the request the transport received: signed, final attempt
     */
    public function __construct(
        public readonly int $status,
        array $headers,
        private readonly StreamInterface $body,
        public readonly Request $request,
        public readonly float $durationMs = 0.0,
    ) {
        $this->headers = self::normalizeHeaders($headers);
    }

    public function status(): int
    {
        return $this->status;
    }

    /** 200-299 */
    public function successful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /** 400 and above */
    public function failed(): bool
    {
        return $this->status >= 400;
    }

    public function ok(): bool
    {
        return $this->status === 200;
    }

    /** First value, case-insensitive. */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }

    /** @return array<string, list<string>> */
    public function headers(): array
    {
        return $this->headers;
    }

    /** Reads the stream to its end, rewinding first when it is seekable. */
    public function body(): string
    {
        if ($this->body->isSeekable()) {
            $this->body->rewind();
        }

        return $this->body->getContents();
    }

    /**
     * json_decode(body(), true), or with $key the value at that dot path inside it
     * (`error.code`, `errors.file.0`), $default when any segment is missing. Null on an
     * empty or invalid body.
     *
     * The signature mirrors Illuminate\Http\Client\Response::json($key, $default) because
     * PHP ignores extra arguments: without the parameters, code ported from the Laravel
     * client that reads `$response->json('error.code')` silently got the whole document.
     */
    public function json(?string $key = null, mixed $default = null): mixed
    {
        $body = $this->body();
        $decoded = $body === '' ? null : json_decode($body, true);

        if ($key === null) {
            return $decoded;
        }

        foreach (explode('.', $key) as $segment) {
            if (! is_array($decoded) || ! array_key_exists($segment, $decoded)) {
                return $default;
            }

            $decoded = $decoded[$segment];
        }

        return $decoded;
    }

    public function getBody(): StreamInterface
    {
        return $this->body;
    }

    public function attempt(): int
    {
        return $this->request->attempt;
    }

    public function withDurationMs(float $ms): static
    {
        return new self($this->status, $this->headers, $this->body, $this->request, $ms);
    }

    public function withRequest(Request $request): static
    {
        return new self($this->status, $this->headers, $this->body, $request, $this->durationMs);
    }

    /**
     * @param  array<string, string|array<string>>  $headers  values may be a string or any array of strings (PSR-7 style)
     * @return array<string, list<string>>
     */
    private static function normalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            $name = strtolower((string) $name);
            $values = is_array($value) ? $value : [$value];

            foreach ($values as $item) {
                $normalized[$name][] = (string) $item;
            }
        }

        return $normalized;
    }
}
