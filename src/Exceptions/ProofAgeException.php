<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Exceptions;

use ProofAge\Sdk\Http\Response;

/**
 * A non-2xx answer from the API, and the base of every SDK exception.
 *
 * The API does not use one error body. getMessage(), getErrorCode() and getErrorData()
 * read each shape it sends:
 *
 * - `{error: {code, message}}` — most errors (401, 404 MEDIA_NOT_FOUND, 422 submit, 429).
 *   getErrorData() is the `error` object.
 * - `{code, message, ...}` — 402 PAYMENT_METHOD_REQUIRED (with free_verifications_remaining,
 *   trial_ends_at, trial_active) and the media quality errors of an upload (422
 *   FACE_NOT_FOUND, MAX_ATTEMPTS_REACHED, ...; 500 VALIDATION_SERVICE_UNAVAILABLE).
 *   getErrorData() is the whole body.
 * - `{message, errors}` — request validation (422); getErrors() on ValidationException
 *   reads `errors`. `{message}` — 403 access denied, 404 "Resource not found".
 *   getErrorCode() is null for both; getErrorData() is the whole body.
 */
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
        $errorMessage = self::messageFrom($response->json()) ?? ($message ?: 'ProofAge API request failed');

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
        $data = $this->response?->json();

        if (! is_array($data) || array_is_list($data)) {
            return;
        }

        $this->errorData = isset($data['error']) && is_array($data['error']) ? $data['error'] : $data;
    }

    /**
     * `error.message` when the body nests its error, the top-level `message` otherwise.
     */
    private static function messageFrom(mixed $data): ?string
    {
        if (! is_array($data)) {
            return null;
        }

        if (isset($data['error']) && is_array($data['error'])) {
            $message = $data['error']['message'] ?? null;
        } else {
            $message = $data['message'] ?? null;
        }

        return is_string($message) && $message !== '' ? $message : null;
    }
}
