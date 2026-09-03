<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Exceptions;

use ProofAge\Sdk\Exceptions\Concerns\DescribesWebhookFailure;

/**
 * Thrown by Webhooks\WebhookVerifier (and the Laravel middleware) when an inbound
 * webhook fails a check. Never produced by the HTTP request path and carries no
 * Response. toArray() is the body a framework should render with statusCode.
 */
class WebhookVerificationException extends ProofAgeException
{
    use DescribesWebhookFailure;
}
