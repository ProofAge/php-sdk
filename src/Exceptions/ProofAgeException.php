<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Exceptions;

use ProofAge\Sdk\Http\Response;

class ProofAgeException extends \Exception implements ExceptionInterface
{
    protected ?Response $response = null;

    /** @var array<string, mixed> */
    protected array $errorData = [];

    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null, ?Response $response = null)
    {
        parent::__construct($message, $code, $previous);

        $this->response = $response;

        if ($response) {
            $this->parseErrorData();
        }
    }

    public static function fromResponse(Response $response, string $message = ''): static
    {
        $errorMessage = $message ?: 'ProofAge API request failed';

        $json = $response->json();
        if (is_array($json) && isset($json['error']['message']) && is_string($json['error']['message'])) {
            $errorMessage = $json['error']['message'];
        }

        // Subclasses that reshape the constructor (TransportException, WebhookVerificationException)
        // are never built here; the HTTP error family keeps this constructor, so new static is safe.
        // @phpstan-ignore new.static
        return new static($errorMessage, $response->status(), null, $response);
    }

    public function getResponse(): ?Response
    {
        return $this->response;
    }

    /** @return array<string, mixed> */
    public function getErrorData(): array
    {
        return $this->errorData;
    }

    public function getErrorCode(): ?string
    {
        $code = $this->errorData['code'] ?? null;

        return is_string($code) ? $code : null;
    }

    protected function parseErrorData(): void
    {
        if ($this->response && $this->response->json()) {
            $data = $this->response->json();

            if (is_array($data) && isset($data['error']) && is_array($data['error'])) {
                $this->errorData = $data['error'];
            }
        }
    }
}
