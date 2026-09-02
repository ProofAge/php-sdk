<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Http\Psr18;

use ProofAge\Sdk\Exceptions\TransportException;
use ProofAge\Sdk\Http\Body\MultipartBody;
use ProofAge\Sdk\Http\Body\RawBody;
use ProofAge\Sdk\Http\HttpClient;
use ProofAge\Sdk\Http\MultipartEncoder;
use ProofAge\Sdk\Http\Request;
use ProofAge\Sdk\Http\Response;
use ProofAge\Sdk\Stream\ResourceStream;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Sends through a PSR-18 client the consumer already has (Guzzle, Symfony HttpClient,
 * ...), building the request with their PSR-17 factories. psr/http-client and
 * psr/http-factory are not SDK dependencies: PHP resolves these interfaces when this
 * class is instantiated, and a consumer who has a PSR-18 client already has them.
 *
 * Request::$timeout is not applied: PSR-18 has no per-request timeout, so configure it
 * on the client you pass in. The PSR-7 body stream is returned as-is; with a sink it is
 * copied to the file and the response streams read-only from there.
 */
final class Psr18HttpClient implements HttpClient
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {}

    public function send(Request $request): Response
    {
        $psrRequest = $this->requestFactory->createRequest($request->method, $request->url);

        foreach ($request->headers as $name => $value) {
            $psrRequest = $psrRequest->withHeader($name, $value);
        }

        $body = $request->body;

        if ($body instanceof RawBody) {
            if (! $psrRequest->hasHeader('Content-Type')) {
                $psrRequest = $psrRequest->withHeader('Content-Type', $body->contentType);
            }

            $psrRequest = $psrRequest->withBody($this->streamFactory->createStream($body->bytes));
        } elseif ($body instanceof MultipartBody) {
            $encoder = new MultipartEncoder;
            $psrRequest = $psrRequest
                ->withHeader('Content-Type', $encoder->contentType())
                ->withBody($this->streamFactory->createStream($encoder->encode($body)));
        }

        try {
            $psrResponse = $this->client->sendRequest($psrRequest);
        } catch (ClientExceptionInterface $e) {
            throw new TransportException($e->getMessage(), 0, $e);
        }

        $stream = $psrResponse->getBody();

        if ($request->sink !== null) {
            $sink = ResourceStream::open($request->sink, 'wb');

            while (! $stream->eof()) {
                $sink->write($stream->read(65536));
            }

            $sink->close();
            $stream->close();
            $stream = ResourceStream::open($request->sink, 'rb');
        }

        return new Response($psrResponse->getStatusCode(), $psrResponse->getHeaders(), $stream, $request);
    }
}
