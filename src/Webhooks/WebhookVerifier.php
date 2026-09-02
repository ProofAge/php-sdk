<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Webhooks;

use ProofAge\Sdk\Exceptions\ProofAgeException;
use ProofAge\Sdk\Exceptions\WebhookVerificationException;

/**
 * The header-level check sequence for an inbound ProofAge webhook, framework-free:
 * which header is missing, does X-Auth-Client match the API key, is X-Timestamp within
 * tolerance, does X-HMAC-Signature verify over `{timestamp}.{rawBody}`.
 *
 * The codes and messages are those of the proofage/laravel-client middleware and are
 * part of the documented contract. CONFIGURATION_ERROR stays with the middleware: it is
 * about config resolution, which the SDK does not do.
 */
final class WebhookVerifier
{
    private readonly WebhookSignatureVerifier $signatures;

    public function __construct(
        private readonly string $apiKey,
        string $secretKey,
        int $tolerance = 300,
    ) {
        if ($apiKey === '') {
            throw new ProofAgeException('API key is required');
        }

        if ($secretKey === '') {
            throw new ProofAgeException('Secret key is required');
        }

        $this->signatures = new WebhookSignatureVerifier($secretKey, $tolerance);
    }

    /**
     * @throws WebhookVerificationException with, in this order: MISSING_SIGNATURE, MISSING_TIMESTAMP,
     *                                      MISSING_AUTH_CLIENT, INVALID_AUTH_CLIENT, TIMESTAMP_TOO_OLD, INVALID_SIGNATURE
     */
    public function verify(?string $signature, ?string $timestamp, ?string $authClient, string $rawBody): void
    {
        if (! $signature) {
            throw new WebhookVerificationException('MISSING_SIGNATURE', 'X-HMAC-Signature header is required');
        }

        if (! $timestamp) {
            throw new WebhookVerificationException('MISSING_TIMESTAMP', 'X-Timestamp header is required');
        }

        if (! $authClient) {
            throw new WebhookVerificationException('MISSING_AUTH_CLIENT', 'X-Auth-Client header is required');
        }

        if ($this->apiKey !== $authClient) {
            throw new WebhookVerificationException('INVALID_AUTH_CLIENT', 'X-Auth-Client header is invalid');
        }

        $timestampInt = (int) $timestamp;

        if (! $this->signatures->isTimestampValid($timestampInt)) {
            throw new WebhookVerificationException('TIMESTAMP_TOO_OLD', 'Timestamp is too old');
        }

        if (! $this->signatures->verify($rawBody, $timestampInt, $signature)) {
            throw new WebhookVerificationException('INVALID_SIGNATURE', 'HMAC signature is invalid');
        }
    }

    /**
     * Case-insensitive header lookup, then verify().
     *
     * @param  array<string, string|list<string>>  $headers
     *
     * @throws WebhookVerificationException
     */
    public function verifyHeaders(array $headers, string $rawBody): void
    {
        $this->verify(
            self::header($headers, 'X-HMAC-Signature'),
            self::header($headers, 'X-Timestamp'),
            self::header($headers, 'X-Auth-Client'),
            $rawBody,
        );
    }

    /** @param array<string, string|list<string>> $headers */
    private static function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp($key, $name) !== 0) {
                continue;
            }

            if (is_array($value)) {
                return $value[0] ?? null;
            }

            return $value;
        }

        return null;
    }
}
