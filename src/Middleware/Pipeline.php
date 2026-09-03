<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Middleware;

use ProofAge\Sdk\Http\HttpClient;
use ProofAge\Sdk\Http\Request;
use ProofAge\Sdk\Http\Response;

/**
 * Client -> [Retry] -> [user middleware, first pushed outermost] -> [Sign] -> [Transport].
 *
 * A middleware is `callable(Request, callable(Request): Response): Response`; one that
 * returns without calling $next short-circuits below itself, so nothing is signed, no
 * event fires and nothing is sent.
 */
final class Pipeline
{
    /** @var list<array{name: string|null, middleware: callable(Request, callable(Request): Response): Response}> */
    private array $middleware = [];

    public function __construct(
        private readonly HttpClient $transport,
        private readonly SignMiddleware $sign,
        private readonly RetryMiddleware $retry,
    ) {}

    /** @param callable(Request, callable(Request): Response): Response $middleware */
    public function push(callable $middleware, ?string $name = null): void
    {
        $this->middleware[] = ['name' => $name, 'middleware' => $middleware];
    }

    public function remove(string $name): void
    {
        $this->middleware = array_values(array_filter(
            $this->middleware,
            static fn (array $entry): bool => $entry['name'] !== $name,
        ));
    }

    public function send(Request $request): Response
    {
        // Static closures capturing only what they call: a closure bound to $this would
        // carry the pipeline, and through it the signer and its secret, into any exception
        // trace that records frame arguments (zend.exception_ignore_args=0, PHP's default).
        $transport = $this->transport;
        $sign = $this->sign;
        $send = static fn (Request $request): Response => $transport->send($request);
        $handler = static fn (Request $request): Response => $sign->handle($request, $send);

        foreach (array_reverse($this->middleware) as $entry) {
            $next = $handler;
            $middleware = $entry['middleware'];
            $handler = static fn (Request $request): Response => $middleware($request, $next);
        }

        return $this->retry->handle($request, $handler);
    }
}
