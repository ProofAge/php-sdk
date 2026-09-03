<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Http\Curl;

use ProofAge\Sdk\Exceptions\TransportException;
use ProofAge\Sdk\Http\Body\MultipartBody;
use ProofAge\Sdk\Http\Body\RawBody;
use ProofAge\Sdk\Http\HttpClient;
use ProofAge\Sdk\Http\MultipartEncoder;
use ProofAge\Sdk\Http\Request;
use ProofAge\Sdk\Http\Response;
use ProofAge\Sdk\Stream\ResourceStream;

/**
 * The default transport: one cURL easy handle per send(), synchronous, no redirects.
 *
 * The response body is written into php://temp (spilling to disk past 2 MB) or, when
 * the request has a sink, straight into that file; either way the PHP heap never holds
 * the whole body as a string. cURL's easy interface cannot hand out a lazily-read socket
 * stream, so even a `stream: true` request is fully received before send() returns.
 */
final class CurlHttpClient implements HttpClient
{
    /** Seconds; the value Illuminate\Http\Client\PendingRequest applies by default. */
    public const CONNECT_TIMEOUT = 10;

    /**
     * cURL errors raised before anything touches the network, by the URL alone. They are
     * deterministic, so the TransportException carrying them is marked not retryable.
     */
    private const LOCAL_URL_ERRORS = [CURLE_UNSUPPORTED_PROTOCOL, CURLE_URL_MALFORMAT];

    public function send(Request $request): Response
    {
        $url = $request->url;
        $method = $request->method;

        if ($url === '' || $method === '') {
            throw new \InvalidArgumentException($url === '' ? 'Request URL is empty' : 'Request method is empty');
        }

        $handle = curl_init();

        if ($handle === false) {
            throw new TransportException('Could not initialise cURL');
        }

        [$headers, $postFields] = $this->prepareBody($request);

        $headerLines = [];

        foreach ($headers as $name => $value) {
            $headerLines[] = "{$name}: {$value}";
        }

        // Neither the API nor PHP's built-in server speaks 100-continue, and waiting for it
        // costs a second per POST; Guzzle drops it for bodies under 1 MB for the same reason.
        $headerLines[] = 'Expect:';

        $body = $this->openBody($request->sink);

        /** @var array<string, list<string>> $responseHeaders */
        $responseHeaders = [];

        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_TIMEOUT => $request->timeout,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_NOBODY => $method === 'HEAD',
            CURLOPT_HEADERFUNCTION => static function (\CurlHandle $handle, string $line) use (&$responseHeaders): int {
                if (str_starts_with($line, 'HTTP/')) {
                    // A new status line: whatever came before was an interim response.
                    $responseHeaders = [];
                } elseif (($colon = strpos($line, ':')) !== false) {
                    // The name is kept as the server spelled it; Response merges spellings.
                    $responseHeaders[trim(substr($line, 0, $colon))][] = trim(substr($line, $colon + 1));
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function (\CurlHandle $handle, string $data) use ($body): int {
                $written = fwrite($body, $data);

                return $written === false ? -1 : $written;
            },
        ]);

        if ($postFields !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $postFields);
        }

        if (curl_exec($handle) === false) {
            $errno = curl_errno($handle);
            $error = curl_error($handle);
            fclose($body);

            throw new TransportException(
                $error !== '' ? $error : "cURL error {$errno}",
                $errno,
                retryable: ! in_array($errno, self::LOCAL_URL_ERRORS, true),
            );
        }

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        if ($request->sink !== null) {
            fclose($body);
            $stream = ResourceStream::open($request->sink, 'rb');
        } else {
            rewind($body);
            $stream = new ResourceStream($body);
        }

        return new Response($status, $responseHeaders, $stream, $request);
    }

    /**
     * @return array{array<string, string>, string|null} headers to send, and the POST fields when there is a body
     */
    private function prepareBody(Request $request): array
    {
        $headers = $request->headers;
        $body = $request->body;

        if ($body instanceof RawBody) {
            if ($request->header('Content-Type') === null) {
                $headers['Content-Type'] = $body->contentType;
            }

            return [$headers, $body->bytes];
        }

        if ($body instanceof MultipartBody) {
            $encoder = new MultipartEncoder;
            $headers = array_filter($headers, static fn (string $name): bool => strcasecmp($name, 'Content-Type') !== 0, ARRAY_FILTER_USE_KEY);
            $headers['Content-Type'] = $encoder->contentType();

            return [$headers, $encoder->encode($body)];
        }

        if (! in_array($request->method, ['GET', 'HEAD'], true) && $request->header('Content-Length') === null) {
            $headers['Content-Length'] = '0';
        }

        return [$headers, null];
    }

    /** @return resource */
    private function openBody(?string $sink)
    {
        if ($sink === null) {
            return ResourceStream::temp();
        }

        $handle = @fopen($sink, 'wb');

        if ($handle === false) {
            throw new \RuntimeException("Could not open sink {$sink} for writing");
        }

        return $handle;
    }
}
