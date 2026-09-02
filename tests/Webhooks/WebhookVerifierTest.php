<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Tests\Webhooks;

use PHPUnit\Framework\TestCase;
use ProofAge\Sdk\Exceptions\ProofAgeException;
use ProofAge\Sdk\Exceptions\WebhookVerificationException;
use ProofAge\Sdk\Webhooks\WebhookVerifier;

/**
 * The header-level check sequence of the Laravel middleware
 * (src/Middleware/VerifyWebhookSignature.php), framework-free. Codes and messages are
 * part of the documented contract and must not drift.
 */
class WebhookVerifierTest extends TestCase
{
    private const API_KEY = 'test-api-key';

    private const SECRET = 'test-secret-key';

    private function verifier(int $tolerance = 300): WebhookVerifier
    {
        return new WebhookVerifier(self::API_KEY, self::SECRET, $tolerance);
    }

    private function sign(string $body, int $timestamp): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$body, self::SECRET);
    }

    /** @return array{string, string} [code, message] */
    private function failure(callable $call): array
    {
        try {
            $call();
        } catch (WebhookVerificationException $e) {
            $this->assertSame(401, $e->statusCode);

            return [$e->errorCode, $e->getMessage()];
        }

        $this->fail('Expected a WebhookVerificationException.');
    }

    public function test_constructor_rejects_empty_keys(): void
    {
        try {
            new WebhookVerifier('', self::SECRET);
            $this->fail('Expected a ProofAgeException.');
        } catch (ProofAgeException $e) {
            $this->assertSame('API key is required', $e->getMessage());
        }

        try {
            new WebhookVerifier(self::API_KEY, '');
            $this->fail('Expected a ProofAgeException.');
        } catch (ProofAgeException $e) {
            $this->assertSame('Secret key is required', $e->getMessage());
        }
    }

    public function test_a_valid_delivery_passes(): void
    {
        $body = '{"verification_id":"ver_1","status":"approved"}';
        $timestamp = time();

        $this->verifier()->verify($this->sign($body, $timestamp), (string) $timestamp, self::API_KEY, $body);

        $this->addToAssertionCount(1);
    }

    public function test_missing_signature(): void
    {
        $this->assertSame(
            ['MISSING_SIGNATURE', 'X-HMAC-Signature header is required'],
            $this->failure(fn () => $this->verifier()->verify(null, (string) time(), self::API_KEY, '{}')),
        );
        $this->assertSame(
            ['MISSING_SIGNATURE', 'X-HMAC-Signature header is required'],
            $this->failure(fn () => $this->verifier()->verify('', (string) time(), self::API_KEY, '{}')),
        );
    }

    public function test_missing_timestamp(): void
    {
        $this->assertSame(
            ['MISSING_TIMESTAMP', 'X-Timestamp header is required'],
            $this->failure(fn () => $this->verifier()->verify('sig', null, self::API_KEY, '{}')),
        );
    }

    public function test_missing_auth_client(): void
    {
        $this->assertSame(
            ['MISSING_AUTH_CLIENT', 'X-Auth-Client header is required'],
            $this->failure(fn () => $this->verifier()->verify('sig', (string) time(), null, '{}')),
        );
    }

    public function test_invalid_auth_client(): void
    {
        $this->assertSame(
            ['INVALID_AUTH_CLIENT', 'X-Auth-Client header is invalid'],
            $this->failure(fn () => $this->verifier()->verify('sig', (string) time(), 'wrong-client', '{}')),
        );
    }

    public function test_timestamp_too_old_or_in_the_future(): void
    {
        $body = '{}';
        $old = time() - 600;
        $future = time() + 600;

        $this->assertSame(
            ['TIMESTAMP_TOO_OLD', 'Timestamp is too old'],
            $this->failure(fn () => $this->verifier()->verify($this->sign($body, $old), (string) $old, self::API_KEY, $body)),
        );
        $this->assertSame(
            ['TIMESTAMP_TOO_OLD', 'Timestamp is too old'],
            $this->failure(fn () => $this->verifier()->verify($this->sign($body, $future), (string) $future, self::API_KEY, $body)),
        );

        $this->verifier(1000)->verify($this->sign($body, $old), (string) $old, self::API_KEY, $body);
        $this->addToAssertionCount(1);
    }

    public function test_invalid_signature(): void
    {
        $this->assertSame(
            ['INVALID_SIGNATURE', 'HMAC signature is invalid'],
            $this->failure(fn () => $this->verifier()->verify('invalid-signature', (string) time(), self::API_KEY, '{}')),
        );
    }

    public function test_checks_run_in_the_documented_order(): void
    {
        // Everything is wrong; the first check in the sequence is the one reported.
        [$code] = $this->failure(fn () => $this->verifier()->verify(null, null, null, ''));
        $this->assertSame('MISSING_SIGNATURE', $code);

        [$code] = $this->failure(fn () => $this->verifier()->verify('bad', null, null, ''));
        $this->assertSame('MISSING_TIMESTAMP', $code);

        [$code] = $this->failure(fn () => $this->verifier()->verify('bad', '1', null, ''));
        $this->assertSame('MISSING_AUTH_CLIENT', $code);

        [$code] = $this->failure(fn () => $this->verifier()->verify('bad', '1', 'wrong', ''));
        $this->assertSame('INVALID_AUTH_CLIENT', $code);

        [$code] = $this->failure(fn () => $this->verifier()->verify('bad', '1', self::API_KEY, ''));
        $this->assertSame('TIMESTAMP_TOO_OLD', $code);
    }

    public function test_the_canonical_json_fallback_applies(): void
    {
        $sent = '{"ip_timezone":"Europe\/Berlin"}';
        $signed = '{"ip_timezone":"Europe/Berlin"}';
        $timestamp = time();

        $this->verifier()->verify($this->sign($signed, $timestamp), (string) $timestamp, self::API_KEY, $sent);

        $this->addToAssertionCount(1);
    }

    public function test_verify_headers_looks_headers_up_case_insensitively_and_accepts_lists(): void
    {
        $body = '{"status":"approved"}';
        $timestamp = time();

        $this->verifier()->verifyHeaders([
            'x-hmac-signature' => $this->sign($body, $timestamp),
            'X-TIMESTAMP' => [(string) $timestamp],
            'X-Auth-Client' => self::API_KEY,
            'X-ProofAge-Webhook-Delivery-Id' => 'dlv_1',
        ], $body);

        $this->addToAssertionCount(1);

        $this->assertSame(
            ['MISSING_TIMESTAMP', 'X-Timestamp header is required'],
            $this->failure(fn () => $this->verifier()->verifyHeaders(['X-HMAC-Signature' => 'sig', 'X-Auth-Client' => self::API_KEY], $body)),
        );
    }
}
