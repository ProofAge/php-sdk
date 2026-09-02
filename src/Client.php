<?php

declare(strict_types=1);

namespace ProofAge\Sdk;

use ProofAge\Sdk\Exceptions\DefaultExceptionFactory;
use ProofAge\Sdk\Exceptions\ExceptionFactory;
use ProofAge\Sdk\Exceptions\ProofAgeException;
use ProofAge\Sdk\Http\Body\FilePart;
use ProofAge\Sdk\Http\Body\MultipartBody;
use ProofAge\Sdk\Http\Body\RawBody;
use ProofAge\Sdk\Http\Curl\CurlHttpClient;
use ProofAge\Sdk\Http\HttpClient;
use ProofAge\Sdk\Http\Request;
use ProofAge\Sdk\Http\Response;
use ProofAge\Sdk\Http\RetryPolicy;
use ProofAge\Sdk\Middleware\Pipeline;
use ProofAge\Sdk\Middleware\RetryMiddleware;
use ProofAge\Sdk\Middleware\SignMiddleware;
use ProofAge\Sdk\Signing\Signer;

/**
 * Entry point. Validates config, builds requests, runs them through
 * Retry -> user middleware -> Sign -> transport, and maps a non-2xx response to an
 * exception through the ExceptionFactory seam.
 *
 * Not final: consumers mock it, and the Laravel package subclasses it.
 */
class Client
{
    /** @var array<string, mixed> */
    protected array $config;

    private HttpClient $transport;

    private SignMiddleware $sign;

    private Pipeline $pipeline;

    private ExceptionFactory $exceptions;

    /**
     * @param  array{api_key?: string, secret_key?: string, base_url?: string, version?: string, timeout?: int, retry_attempts?: int, retry_delay?: int, download_retry_attempts?: int}  $config
     * @param  HttpClient|null  $transport  defaults to CurlHttpClient
     * @param  ExceptionFactory|null  $exceptions  defaults to the SDK's own exception classes
     */
    public function __construct(array $config, ?HttpClient $transport = null, ?ExceptionFactory $exceptions = null)
    {
        $this->config = array_merge([
            'version' => 'v1',
            'timeout' => 30,
            'retry_attempts' => 3,
            'retry_delay' => 1000,
            'download_retry_attempts' => 1,
        ], $config);

        $this->validateConfig();

        $this->transport = $transport ?? new CurlHttpClient;
        $this->exceptions = $exceptions ?? new DefaultExceptionFactory;
        $this->sign = new SignMiddleware((string) $this->config['api_key'], new Signer((string) $this->config['secret_key']));
        $this->pipeline = new Pipeline($this->transport, $this->sign, new RetryMiddleware);
    }

    /**
     * JSON or multipart request with the interactive retry policy.
     *
     * @param  array<string, mixed>  $data  JSON body, or form fields when $files is given
     * @param  array<string, string|\SplFileInfo|FilePart>  $files  form field => path, file object or part
     *
     * @throws ProofAgeException on a non-2xx response (AuthenticationException for 401, ValidationException for 422)
     * @throws Exceptions\TransportException when every attempt failed below HTTP
     * @throws \InvalidArgumentException for a file that does not exist
     */
    public function makeRequest(string $method, string $endpoint, array $data = [], array $files = []): Response
    {
        [$url, $path] = $this->resolve($endpoint);
        $headers = ['Accept' => 'application/json'];

        if ($files !== []) {
            $parts = [];

            foreach ($files as $name => $file) {
                $parts[] = $this->filePart((string) $name, $file);
            }

            $body = new MultipartBody($data, $parts);
        } elseif ($data !== []) {
            $body = new RawBody($this->encodeJson($data), 'application/json');
            $headers['Content-Type'] = 'application/json';
        } else {
            $body = null;
        }

        $request = new Request(
            $method,
            $url,
            $path,
            $headers,
            $body,
            RetryPolicy::interactive($this->retryAttempts(), $this->retryDelay()),
            $this->timeout(),
        );

        return $this->handleResponse($this->pipeline->send($request));
    }

    /**
     * Fetch a binary endpoint without JSON decoding, with the download retry policy.
     *
     * The public API signs GET requests over method + path with an empty body, which is
     * exactly what a bodyless GET sends, so signing is unchanged. What differs from
     * makeRequest() is that the response body is never decoded and, unless a $sink is
     * given, never held as a string.
     *
     * @param  string|null  $sink  Absolute path to stream the body into. When null the body
     *                             is returned as a PSR-7 stream over php://temp.
     */
    public function makeStreamedRequest(string $method, string $endpoint, ?string $sink = null): Response
    {
        [$url, $path] = $this->resolve($endpoint);

        $request = new Request(
            $method,
            $url,
            $path,
            ['Accept' => '*/*'],
            null,
            RetryPolicy::download($this->downloadRetryAttempts(), $this->retryDelay()),
            $this->timeout(),
            $sink,
            $sink === null,
        );

        return $this->handleResponse($this->pipeline->send($request));
    }

    /**
     * Runs once per HTTP attempt, before signing; first pushed is outermost.
     *
     * @param  callable(Request, callable(Request): Response): Response  $middleware
     */
    public function pushMiddleware(callable $middleware, ?string $name = null): static
    {
        $this->pipeline->push($middleware, $name);

        return $this;
    }

    public function removeMiddleware(string $name): static
    {
        $this->pipeline->remove($name);

        return $this;
    }

    /** @param callable(Events\RequestEvent): void $listener */
    public function onRequest(callable $listener): static
    {
        $this->sign->onRequest($listener);

        return $this;
    }

    /** @param callable(Events\ResponseEvent): void $listener */
    public function onResponse(callable $listener): static
    {
        $this->sign->onResponse($listener);

        return $this;
    }

    /** @param callable(Events\ErrorEvent): void $listener */
    public function onError(callable $listener): static
    {
        $this->sign->onError($listener);

        return $this;
    }

    public function transport(): HttpClient
    {
        return $this->transport;
    }

    protected function validateConfig(): void
    {
        if (empty($this->config['api_key'])) {
            throw new ProofAgeException('API key is required');
        }

        if (empty($this->config['secret_key'])) {
            throw new ProofAgeException('Secret key is required');
        }

        if (empty($this->config['base_url'])) {
            throw new ProofAgeException('Base URL is required');
        }
    }

    protected function handleResponse(Response $response): Response
    {
        if ($response->successful()) {
            return $response;
        }

        throw $this->exceptions->fromResponse($response);
    }

    /**
     * The URL to send and the canonical path to sign: `/{version}/{endpoint}`, with any
     * query string normalized the way the server normalizes it before signing.
     *
     * @return array{string, string} [url, path]
     */
    private function resolve(string $endpoint): array
    {
        $endpoint = ltrim($endpoint, '/');
        $query = '';

        if (($mark = strpos($endpoint, '?')) !== false) {
            $query = substr($endpoint, $mark + 1);
            $endpoint = substr($endpoint, 0, $mark);
        }

        $baseUrl = rtrim((string) $this->config['base_url'], '/');
        $version = (string) $this->config['version'];

        $path = '/'.$version.'/'.$endpoint;
        $url = "{$baseUrl}/{$version}/{$endpoint}";

        $normalized = Signer::normalizeQueryString($query);

        if ($normalized !== '') {
            $path .= '?'.$normalized;
            $url .= '?'.$normalized;
        }

        return [$url, $path];
    }

    /** @param array<string, mixed> $data */
    private function encodeJson(array $data): string
    {
        try {
            return json_encode($data, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ProofAgeException('Request body is not JSON-encodable', 0, $e);
        }
    }

    private function filePart(string $name, mixed $file): FilePart
    {
        if (is_string($file) || $file instanceof \SplFileInfo || $file instanceof FilePart) {
            return FilePart::from($name, $file);
        }

        throw new \InvalidArgumentException("Unsupported file for field {$name}: expected a path, an SplFileInfo or a FilePart");
    }

    private function timeout(): int
    {
        return (int) $this->config['timeout'];
    }

    private function retryAttempts(): int
    {
        return max(1, (int) $this->config['retry_attempts']);
    }

    private function retryDelay(): int
    {
        return (int) $this->config['retry_delay'];
    }

    private function downloadRetryAttempts(): int
    {
        return max(1, (int) ($this->config['download_retry_attempts'] ?? 1));
    }
}
