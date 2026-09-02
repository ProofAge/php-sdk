<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Tests\Webhooks;

use PHPUnit\Framework\TestCase;
use ProofAge\Sdk\Webhooks\WebhookSignatureVerifier;

class WebhookSignatureVerifierTest extends TestCase
{
    protected string $secret = 'test-secret-key';

    public function test_verify_returns_true_for_valid_signature(): void
    {
        $verifier = new WebhookSignatureVerifier($this->secret);
        $payload = '{"event": "verification.completed"}';
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, $this->secret);

        $this->assertTrue($verifier->verify($payload, $timestamp, $signature));
    }

    public function test_verify_returns_false_for_invalid_signature(): void
    {
        $verifier = new WebhookSignatureVerifier($this->secret);
        $payload = '{"event": "verification.completed"}';
        $timestamp = time();

        $this->assertFalse($verifier->verify($payload, $timestamp, 'invalid-signature'));
    }

    public function test_verify_returns_false_for_wrong_secret(): void
    {
        $verifier = new WebhookSignatureVerifier($this->secret);
        $payload = '{"event": "verification.completed"}';
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, 'wrong-secret');

        $this->assertFalse($verifier->verify($payload, $timestamp, $signature));
    }

    public function test_verify_returns_false_for_tampered_payload(): void
    {
        $verifier = new WebhookSignatureVerifier($this->secret);
        $originalPayload = '{"status": "approved"}';
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$originalPayload, $this->secret);

        $tamperedPayload = '{"status": "declined"}';
        $this->assertFalse($verifier->verify($tamperedPayload, $timestamp, $signature));
    }

    public function test_verify_with_empty_payload(): void
    {
        $verifier = new WebhookSignatureVerifier($this->secret);
        $payload = '';
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, $this->secret);

        $this->assertTrue($verifier->verify($payload, $timestamp, $signature));
    }

    public function test_verify_accepts_a_signature_over_the_canonical_json_form(): void
    {
        // Deliveries from the server signed json_encode($payload, UNESCAPED_SLASHES|UNESCAPED_UNICODE)
        // while sending the default-escaped bytes; the fallback re-encodes and verifies again.
        $verifier = new WebhookSignatureVerifier($this->secret);
        $sent = '{"ip_timezone":"Europe\/Berlin"}';
        $signed = '{"ip_timezone":"Europe/Berlin"}';
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$signed, $this->secret);

        $this->assertTrue($verifier->verify($sent, $timestamp, $signature));
    }

    public function test_verify_does_not_accept_a_canonical_signature_for_invalid_json(): void
    {
        $verifier = new WebhookSignatureVerifier($this->secret);
        $timestamp = time();

        $this->assertFalse($verifier->verify('not json', $timestamp, hash_hmac('sha256', $timestamp.'.null', $this->secret)));
    }

    public function test_is_timestamp_valid_within_tolerance(): void
    {
        $verifier = new WebhookSignatureVerifier($this->secret, 300);
        $timestamp = time() - 100;

        $this->assertTrue($verifier->isTimestampValid($timestamp));
    }

    public function test_is_timestamp_valid_at_boundary(): void
    {
        $verifier = new WebhookSignatureVerifier($this->secret, 300);
        $timestamp = time() - 300;

        $this->assertTrue($verifier->isTimestampValid($timestamp));
    }

    public function test_is_timestamp_valid_expired(): void
    {
        $verifier = new WebhookSignatureVerifier($this->secret, 300);
        $timestamp = time() - 301;

        $this->assertFalse($verifier->isTimestampValid($timestamp));
    }

    public function test_custom_tolerance(): void
    {
        $verifier = new WebhookSignatureVerifier($this->secret, 60);

        $this->assertTrue($verifier->isTimestampValid(time() - 50));
        $this->assertTrue($verifier->isTimestampValid(time() - 60));
        $this->assertFalse($verifier->isTimestampValid(time() - 61));
    }

    public function test_is_timestamp_valid_rejects_future_timestamps(): void
    {
        $verifier = new WebhookSignatureVerifier($this->secret, 300);
        $timestamp = time() + 3600;

        $this->assertFalse($verifier->isTimestampValid($timestamp));
    }

    public function test_generate_signature_matches_verify(): void
    {
        $verifier = new WebhookSignatureVerifier($this->secret);
        $payload = '{"id": "ver_123", "status": "approved"}';
        $timestamp = time();

        $signature = $verifier->generateSignature($payload, $timestamp);

        $this->assertTrue($verifier->verify($payload, $timestamp, $signature));
    }

    public function test_generate_signature_is_deterministic(): void
    {
        $verifier = new WebhookSignatureVerifier($this->secret);
        $payload = '{"test": true}';
        $timestamp = 1700000000;

        $sig1 = $verifier->generateSignature($payload, $timestamp);
        $sig2 = $verifier->generateSignature($payload, $timestamp);

        $this->assertSame($sig1, $sig2);
    }

    public function test_different_timestamps_produce_different_signatures(): void
    {
        $verifier = new WebhookSignatureVerifier($this->secret);
        $payload = '{"test": true}';

        $sig1 = $verifier->generateSignature($payload, 1700000000);
        $sig2 = $verifier->generateSignature($payload, 1700000001);

        $this->assertNotSame($sig1, $sig2);
    }
}
