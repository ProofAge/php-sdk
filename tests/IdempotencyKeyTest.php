<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProofAge\Sdk\Client;
use ProofAge\Sdk\Exceptions\ProofAgeException;
use ProofAge\Sdk\Testing\FakeHttpClient;
use ProofAge\Sdk\Tests\Support\TimestampedSignature;

/**
 * Creates carry an Idempotency-Key that survives the SDK's own retries, and every attempt is
 * signed afresh: same key, new X-Timestamp, new signature.
 */
class IdempotencyKeyTest extends TestCase
{
    private const UUID_V4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    /** @var list<int> microseconds the retry loop was asked to wait */
    private array $waits = [];

    /** @param array<string, mixed> $routes */
    private function client(array $routes, ?FakeHttpClient &$fake = null): Client
    {
        $fake = new FakeHttpClient($routes);
        $this->waits = [];

        return new Client([
            'api_key' => 'test-api-key',
            'secret_key' => 'test-secret-key',
            'base_url' => 'https://api.test.com',
            'retry_delay' => 1000,
        ], $fake, null, function (int $microseconds): void {
            $this->waits[] = $microseconds;
        });
    }

    public function test_a_verification_create_generates_a_uuid_v4_key(): void
    {
        $client = $this->client(['*' => FakeHttpClient::json(['id' => 'ver_1'], 201)], $fake);

        $client->verifications()->create(['external_id' => 'u-1']);

        $this->assertMatchesRegularExpression(self::UUID_V4, (string) $fake->sent()[0]->header('Idempotency-Key'));
    }

    public function test_each_logical_create_gets_its_own_key(): void
    {
        $client = $this->client(['*' => FakeHttpClient::json(['id' => 'ver_1'], 201)], $fake);

        $client->verifications()->create(['external_id' => 'u-1']);
        $client->verifications()->create(['external_id' => 'u-1']);

        $this->assertNotSame($fake->sent()[0]->header('Idempotency-Key'), $fake->sent()[1]->header('Idempotency-Key'));
    }

    public function test_a_key_from_the_caller_is_sent_and_is_not_part_of_the_body(): void
    {
        $client = $this->client(['*' => FakeHttpClient::json(['id' => 'ver_1'], 201)], $fake);

        $client->verifications()->create(['external_id' => 'u-1'], idempotencyKey: 'order-42');

        $sent = $fake->sent()[0];
        $this->assertSame('order-42', $sent->header('Idempotency-Key'));
        $this->assertSame('{"external_id":"u-1"}', $sent->body?->bytes);
    }

    public function test_a_webhook_subscription_create_sends_a_key_too(): void
    {
        $client = $this->client(['*' => FakeHttpClient::json(['id' => 'sub_1'], 201)], $fake);

        $client->webhookSubscriptions()->create(['url' => 'https://example.com/hook']);
        $client->webhookSubscriptions()->create(['url' => 'https://example.com/hook'], 'sub-key');

        $this->assertMatchesRegularExpression(self::UUID_V4, (string) $fake->sent()[0]->header('Idempotency-Key'));
        $this->assertSame('sub-key', $fake->sent()[1]->header('Idempotency-Key'));
    }

    public function test_other_calls_send_no_key(): void
    {
        $client = $this->client(['*' => FakeHttpClient::json(['id' => 'ver_1', 'data' => []])], $fake);

        $client->verifications('ver_1')->find('ver_1');
        $client->verifications()->list();
        $client->verifications('ver_1')->submit();
        $client->webhookSubscriptions()->list();
        $client->webhookSubscriptions()->delete('sub_1');
        $client->workspace()->get();

        foreach ($fake->sent() as $request) {
            $this->assertNull($request->header('Idempotency-Key'), "{$request->method} {$request->path} must not carry a key.");
        }
    }

    public function test_a_retry_reuses_the_key_and_is_signed_afresh(): void
    {
        $client = $this->client(['*' => [FakeHttpClient::json(['message' => 'down'], 503), FakeHttpClient::json(['id' => 'ver_1'], 201)]], $fake);

        $result = $client->verifications()->create(['external_id' => 'u-1']);

        $this->assertSame('ver_1', $result['id']);
        [$first, $second] = $fake->sent();
        $this->assertSame($first->header('Idempotency-Key'), $second->header('Idempotency-Key'));
        $this->assertSame(1, $first->attempt);
        $this->assertSame(2, $second->attempt);

        foreach ([$first, $second] as $request) {
            $this->assertMatchesRegularExpression('/^\d{10}$/', (string) $request->header('X-Timestamp'));
            $this->assertSame(TimestampedSignature::forRequest($request, 'POST/v1/verifications{"external_id":"u-1"}', 'test-secret-key'), $request->header('X-HMAC-Signature'));
            $this->assertEqualsWithDelta(time(), (int) $request->header('X-Timestamp'), 5, 'The timestamp is read on the attempt, not reused.');
        }
    }

    public function test_a_timeout_is_retried_with_the_same_key(): void
    {
        $client = $this->client(['*' => [FakeHttpClient::timeout(), FakeHttpClient::json(['id' => 'ver_1'], 201)]], $fake);

        $client->verifications()->create(['external_id' => 'u-1']);

        $fake->assertSentCount(2);
        $this->assertSame($fake->sent()[0]->header('Idempotency-Key'), $fake->sent()[1]->header('Idempotency-Key'));
    }

    public function test_a_409_while_the_first_request_runs_is_retried_after_its_retry_after(): void
    {
        $inProgress = FakeHttpClient::json(['error' => ['code' => 'IDEMPOTENCY_KEY_IN_PROGRESS', 'message' => 'Busy.']], 409, ['Retry-After' => '2']);
        $client = $this->client(['*' => [$inProgress, FakeHttpClient::json(['id' => 'ver_1'], 201)]], $fake);

        $result = $client->verifications()->create(['external_id' => 'u-1'], 'order-1');

        $this->assertSame('ver_1', $result['id']);
        $this->assertSame([2_000_000], $this->waits);
        $this->assertSame('order-1', $fake->sent()[1]->header('Idempotency-Key'));
    }

    public function test_reusing_a_key_for_another_request_is_not_retried(): void
    {
        $client = $this->client(['*' => FakeHttpClient::json(['error' => ['code' => 'IDEMPOTENCY_KEY_REUSED', 'message' => 'Other request.']], 409)], $fake);

        try {
            $client->verifications()->create(['external_id' => 'u-2'], 'order-1');
            $this->fail('Expected a ProofAgeException.');
        } catch (ProofAgeException $e) {
            $this->assertSame(409, $e->getCode());
            $this->assertSame('IDEMPOTENCY_KEY_REUSED', $e->getErrorCode());
        }

        $fake->assertSentCount(1);
    }

    #[DataProvider('invalidKeys')]
    public function test_an_invalid_key_is_refused_before_anything_is_sent(string $key): void
    {
        $client = $this->client(['*' => FakeHttpClient::json([])], $fake);

        try {
            $client->verifications()->create(['external_id' => 'u-1'], $key);
            $this->fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException) {
        }

        $fake->assertNothingSent();
    }

    /** @return iterable<string, array{string}> */
    public static function invalidKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'space' => ['order 1'];
        yield 'non-ASCII' => ['commande-é'];
        yield 'control character' => ["a\tb"];
        yield 'too long' => [str_repeat('a', 256)];
    }

    public function test_the_longest_key_is_accepted(): void
    {
        $client = $this->client(['*' => FakeHttpClient::json(['id' => 'ver_1'], 201)], $fake);

        $client->verifications()->create(['external_id' => 'u-1'], str_repeat('k', 255));

        $this->assertSame(str_repeat('k', 255), $fake->sent()[0]->header('Idempotency-Key'));
    }
}
