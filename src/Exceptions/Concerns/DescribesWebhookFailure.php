<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Exceptions\Concerns;

/**
 * The body of a webhook verification failure: why it failed and what to answer.
 *
 * In a trait for the same reason as HasValidationErrors — a host framework needs
 * its own class hierarchy, and in Laravel's case also a render() method, which is
 * why it cannot simply extend the SDK class.
 */
trait DescribesWebhookFailure
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
