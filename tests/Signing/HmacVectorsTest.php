<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Tests\Signing;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProofAge\Sdk\Http\Body\FilePart;
use ProofAge\Sdk\Signing\Signer;
use ProofAge\Sdk\Webhooks\WebhookSignatureVerifier;

/**
 * Executes resources/hmac-vectors.json, the golden fixture both this SDK and the
 * ProofAge server run. A vector's `canonical` is asserted before its `expected`
 * so a failure says which half drifted: the canonical string or the HMAC.
 */
class HmacVectorsTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function fixture(): array
    {
        return json_decode(
            (string) file_get_contents(dirname(__DIR__, 2).'/resources/hmac-vectors.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function jsonVectors(): iterable
    {
        $fixture = self::fixture();

        foreach ($fixture['json'] as $vector) {
            yield $vector['name'] => [$vector, $fixture['secret']];
        }
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function multipartVectors(): iterable
    {
        $fixture = self::fixture();

        foreach ($fixture['multipart'] as $vector) {
            yield $vector['name'] => [$vector, $fixture['secret']];
        }
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function webhookVectors(): iterable
    {
        $fixture = self::fixture();

        foreach ($fixture['webhook'] as $vector) {
            yield $vector['name'] => [$vector, $fixture['secret']];
        }
    }

    public function test_the_fixture_has_the_expected_shape(): void
    {
        $fixture = self::fixture();

        $this->assertSame(1, $fixture['version']);
        $this->assertSame('vector-secret-2026', $fixture['secret']);
        $this->assertCount(6, $fixture['json']);
        $this->assertCount(6, $fixture['multipart']);
        $this->assertCount(3, $fixture['webhook']);
    }

    /** @param array<string, mixed> $vector */
    #[DataProvider('jsonVectors')]
    public function test_json_vector(array $vector, string $secret): void
    {
        $path = $vector['path'];

        if (isset($vector['query'])) {
            $path .= '?'.Signer::normalizeQueryString($vector['query']);
        }

        $this->assertSame(
            $vector['canonical'],
            Signer::canonicalRaw($vector['method'], $path, $vector['body']),
            'Canonical string drifted.',
        );

        $this->assertSame(
            $vector['expected'],
            (new Signer($secret))->signRaw($vector['method'], $path, $vector['body']),
            'HMAC drifted although the canonical string matches.',
        );
    }

    /** @param array<string, mixed> $vector */
    #[DataProvider('multipartVectors')]
    public function test_multipart_vector(array $vector, string $secret): void
    {
        $hashes = [];

        foreach ($vector['files'] as $file) {
            $part = new FilePart($file['field'], $file['filename'], (string) base64_decode($file['content_base64'], true));

            $this->assertSame($file['sha256'], $part->sha256(), "FilePart hashed {$file['filename']} differently.");

            $hashes[] = $part->sha256();
        }

        $this->assertSame(
            $vector['canonical'],
            Signer::canonicalMultipart($vector['method'], $vector['path'], $vector['fields'], $hashes),
            'Canonical string drifted.',
        );

        $this->assertSame(
            $vector['expected'],
            (new Signer($secret))->signMultipart($vector['method'], $vector['path'], $vector['fields'], $hashes),
            'HMAC drifted although the canonical string matches.',
        );
    }

    /** @param array<string, mixed> $vector */
    #[DataProvider('webhookVectors')]
    public function test_webhook_vector(array $vector, string $secret): void
    {
        $verifier = new WebhookSignatureVerifier($secret);

        $this->assertSame($vector['expected'], $verifier->generateSignature($vector['payload'], $vector['timestamp']));
        $this->assertTrue($verifier->verify($vector['payload'], $vector['timestamp'], $vector['expected']));

        if (! isset($vector['expected_canonical'])) {
            return;
        }

        // The server signed the JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE form and sent the
        // default-escaped form; the verifier's fallback re-encodes the received bytes and accepts.
        $canonical = json_encode(json_decode($vector['payload'], true), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $this->assertNotSame($vector['payload'], $canonical, 'This vector must carry bytes that re-encode differently.');
        $this->assertSame($vector['expected_canonical'], $verifier->generateSignature((string) $canonical, $vector['timestamp']));
        $this->assertTrue($verifier->verify($vector['payload'], $vector['timestamp'], $vector['expected_canonical']));
    }
}
