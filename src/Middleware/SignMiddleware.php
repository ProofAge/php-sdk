<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Middleware;

use ProofAge\Sdk\Events\ErrorEvent;
use ProofAge\Sdk\Events\Redactor;
use ProofAge\Sdk\Events\RequestEvent;
use ProofAge\Sdk\Events\ResponseEvent;
use ProofAge\Sdk\Exceptions\TransportException;
use ProofAge\Sdk\Http\Request;
use ProofAge\Sdk\Http\Response;
use ProofAge\Sdk\Signing\Signer;

/**
 * The innermost layer: adds X-API-Key and X-HMAC-Signature to whatever reaches it, so
 * nothing a user middleware does can invalidate the signature, and fires the events
 * around the transport call. Duration is measured here with hrtime() and attached to
 * the response before onResponse fires: one clock, one place.
 */
final class SignMiddleware
{
    /** @var list<callable(RequestEvent): void> */
    private array $onRequest = [];

    /** @var list<callable(ResponseEvent): void> */
    private array $onResponse = [];

    /** @var list<callable(ErrorEvent): void> */
    private array $onError = [];

    public function __construct(
        #[\SensitiveParameter] private readonly string $apiKey,
        private readonly Signer $signer,
    ) {}

    /**
     * What print_r() and var_dump() show: the API key masked, the signer (which masks its
     * own secret), and listener counts rather than the listeners, whose captured state is
     * the consumer's business and can be arbitrarily large.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'apiKey' => Redactor::apiKey($this->apiKey),
            'signer' => $this->signer,
            'listeners' => [
                'onRequest' => count($this->onRequest),
                'onResponse' => count($this->onResponse),
                'onError' => count($this->onError),
            ],
        ];
    }

    /** @param callable(RequestEvent): void $listener */
    public function onRequest(callable $listener): self
    {
        $this->onRequest[] = $listener;

        return $this;
    }

    /** @param callable(ResponseEvent): void $listener */
    public function onResponse(callable $listener): self
    {
        $this->onResponse[] = $listener;

        return $this;
    }

    /** @param callable(ErrorEvent): void $listener */
    public function onError(callable $listener): self
    {
        $this->onError[] = $listener;

        return $this;
    }

    /**
     * @param  callable(Request): Response  $next
     */
    public function handle(Request $request, callable $next): Response
    {
        $signed = $request
            ->withHeader('X-API-Key', $this->apiKey)
            ->withHeader('X-HMAC-Signature', $this->signer->sign($request));

        $requestEvent = new RequestEvent($signed);

        foreach ($this->onRequest as $listener) {
            $listener($requestEvent);
        }

        $start = hrtime(true);

        try {
            $response = $next($signed);
        } catch (TransportException $error) {
            $errorEvent = new ErrorEvent($error, $requestEvent, self::elapsedMs($start));

            foreach ($this->onError as $listener) {
                $listener($errorEvent);
            }

            throw $error;
        }

        $response = $response->withDurationMs(self::elapsedMs($start));
        $responseEvent = new ResponseEvent($response);

        foreach ($this->onResponse as $listener) {
            $listener($responseEvent);
        }

        return $response;
    }

    private static function elapsedMs(int $start): float
    {
        return (hrtime(true) - $start) / 1e6;
    }
}
