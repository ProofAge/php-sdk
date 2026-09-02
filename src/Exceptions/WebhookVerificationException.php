<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Exceptions;

/**
 * Thrown by Webhooks\WebhookVerifier (and the Laravel middleware) when an inbound
 * webhook fails a check. Never produced by the HTTP request path and carries no
 * Response. toArray() is the body a framework should render with statusCode.
 */
class WebhookVerificationException extends ProofAgeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $statusCode = 401,
    ) {
        parent::__construct($message, $statusCode);
    }

    /** @return array{error: array{code: string, message: string}} */
    public function toArray(): array
    {
        return [
            'error' => [
                'code' => $this->errorCode,
                'message' => $this->getMessage(),
            ],
        ];
    }
}
