<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Webhooks;

class WebhookSignatureVerifier
{
    public function __construct(
        protected string $secretKey,
        protected int $tolerance = 300,
    ) {}

    public function verify(string $payload, int $timestamp, string $signature): bool
    {
        $expected = $this->generateSignature($payload, $timestamp);

        if (hash_equals($expected, $signature)) {
            return true;
        }

        $canonicalPayload = $this->canonicalizeJsonPayload($payload);

        return $canonicalPayload !== null
            && $canonicalPayload !== $payload
            && hash_equals($this->generateSignature($canonicalPayload, $timestamp), $signature);
    }

    public function isTimestampValid(int $timestamp): bool
    {
        return abs(time() - $timestamp) <= $this->tolerance;
    }

    public function generateSignature(string $payload, int $timestamp): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$payload, $this->secretKey);
    }

    protected function canonicalizeJsonPayload(string $payload): ?string
    {
        if ($payload === '') {
            return null;
        }

        $decoded = json_decode($payload, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        $encoded = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $encoded === false ? null : $encoded;
    }
}
