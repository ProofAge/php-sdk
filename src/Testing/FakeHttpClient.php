<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Testing;

use PHPUnit\Framework\Assert;
use ProofAge\Sdk\Exceptions\TransportException;
use ProofAge\Sdk\Http\HttpClient;
use ProofAge\Sdk\Http\Request;
use ProofAge\Sdk\Http\Response;
use ProofAge\Sdk\Http\RetryPolicy;
use ProofAge\Sdk\Stream\ResourceStream;

/**
 * An HttpClient that answers from a URL-pattern map and records what it received,
 * modelled on Laravel's Http::fake() so tests read the same on plain PHP.
 *
 * Patterns use `*` wildcards and are implicitly prefixed with one, so
 * `api.test.com/v1/workspace` matches `https://api.test.com/v1/workspace` and nothing
 * longer; routes are tried in insertion order. Requests reach the fake signed and once
 * per attempt, so sent() is where a consumer asserts X-HMAC-Signature. A request whose
 * route throws is still recorded: the transport did receive it.
 */
final class FakeHttpClient implements HttpClient
{
    /** @var list<array{regex: string, resolve: \Closure(Request): ?Response}> */
    private array $routes = [];

    /** @var list<Request> */
    private array $sent = [];

    /**
     * @param  array<string, Response|callable|list<Response|callable>>  $routes
     */
    public function __construct(array $routes = [])
    {
        foreach ($routes as $pattern => $response) {
            $this->on((string) $pattern, $response);
        }
    }

    /**
     * @param  Response|callable(Request): Response|list<Response|callable(Request): Response>  $response
     */
    public function on(string $urlPattern, Response|callable|array $response): self
    {
        $this->routes[] = [
            'regex' => '#^'.str_replace('\*', '.*', preg_quote('*'.$urlPattern, '#')).'\z#su',
            'resolve' => self::resolver($response),
        ];

        return $this;
    }

    /**
     * @param  array<string, string|list<string>>  $headers
     */
    public static function json(mixed $data, int $status = 200, array $headers = []): Response
    {
        $bytes = json_encode($data, JSON_THROW_ON_ERROR);

        return self::raw($bytes, $status, $headers + ['Content-Type' => 'application/json']);
    }

    /**
     * @param  array<string, string|list<string>>  $headers
     */
    public static function raw(string $body, int $status = 200, array $headers = []): Response
    {
        return new Response($status, $headers, ResourceStream::fromString($body), self::placeholderRequest());
    }

    /** A route entry that throws TransportException when hit. */
    public static function failedConnection(string $message = 'Connection refused'): callable
    {
        return static function () use ($message): Response {
            throw new TransportException($message);
        };
    }

    public function send(Request $request): Response
    {
        $this->sent[] = $request;

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $request->url) !== 1) {
                continue;
            }

            $response = ($route['resolve'])($request);

            if ($response === null) {
                break;
            }

            return $this->deliver($response, $request);
        }

        throw new \LogicException("No fake response for {$request->method} {$request->url}");
    }

    /** @return list<Request> as received by the transport: signed, per attempt */
    public function sent(): array
    {
        return $this->sent;
    }

    /** @param callable(Request): bool $predicate */
    public function assertSent(callable $predicate): void
    {
        foreach ($this->sent as $request) {
            if ($predicate($request)) {
                self::assert(true, '');

                return;
            }
        }

        self::assert(false, 'No sent request matched the predicate.');
    }

    public function assertSentCount(int $count): void
    {
        $actual = count($this->sent);

        self::assert($actual === $count, "Expected {$count} request(s) to be sent, {$actual} were.");
    }

    public function assertNothingSent(): void
    {
        $actual = count($this->sent);

        self::assert($actual === 0, "Expected no requests to be sent, {$actual} were.");
    }

    /**
     * @param  Response|callable(Request): Response|list<Response|callable(Request): Response>  $response
     * @return \Closure(Request): ?Response
     */
    private static function resolver(Response|callable|array $response): \Closure
    {
        if ($response instanceof Response) {
            return static fn (Request $request): Response => $response;
        }

        if (is_callable($response)) {
            return static fn (Request $request): Response => $response($request);
        }

        $queue = $response;

        return static function (Request $request) use (&$queue): ?Response {
            $next = array_shift($queue);

            if ($next === null) {
                return null;
            }

            return $next instanceof Response ? $next : $next($request);
        };
    }

    /**
     * Every hit gets its own stream over a copy of the stored body. The stored Response
     * is a template that may answer many times, and downloadMedia() hands the stream
     * itself to the caller, who reads it to the end and may close it; sharing one
     * instance made the second hit come back empty.
     */
    private function deliver(Response $response, Request $request): Response
    {
        $bytes = $response->body();

        if ($request->sink === null) {
            return new Response($response->status, $response->headers, ResourceStream::fromString($bytes), $request);
        }

        if (file_put_contents($request->sink, $bytes) === false) {
            throw new \RuntimeException("Could not write to sink {$request->sink}");
        }

        return new Response($response->status, $response->headers, ResourceStream::open($request->sink, 'rb'), $request);
    }

    private static function placeholderRequest(): Request
    {
        return new Request('GET', 'fake://response', '/', [], null, RetryPolicy::download(1, 0), 0);
    }

    private static function assert(bool $condition, string $message): void
    {
        if (class_exists(Assert::class)) {
            Assert::assertTrue($condition, $message);

            return;
        }

        if (! $condition) {
            throw new \AssertionError($message);
        }
    }
}
