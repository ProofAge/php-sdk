<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Tests\Middleware;

use PHPUnit\Framework\TestCase;
use ProofAge\Sdk\Client;
use ProofAge\Sdk\Exceptions\ProofAgeException;
use ProofAge\Sdk\Http\Body\RawBody;
use ProofAge\Sdk\Http\Clock;
use ProofAge\Sdk\Http\Request;
use ProofAge\Sdk\Http\Response;
use ProofAge\Sdk\Http\RetryPolicy;
use ProofAge\Sdk\Middleware\Pipeline;
use ProofAge\Sdk\Middleware\RetryMiddleware;
use ProofAge\Sdk\Middleware\SignMiddleware;
use ProofAge\Sdk\Signing\Signer;
use ProofAge\Sdk\Testing\FakeHttpClient;
use ProofAge\Sdk\Tests\Support\TimestampedSignature;

/**
 * Two byte-identical non-GET requests signed in the same second share a signature, and the API
 * refuses the second with 401 REPLAYED_REQUEST before processing it. The SDK resends it once,
 * after the clock has moved on.
 */
class ReplayedRequestTest extends TestCase
{
    private const NOW = 1795049078;

    private int $now = self::NOW;

    /** @var list<int> seconds waited */
    private array $waits = [];

    private static function replayed(): Response
    {
        return FakeHttpClient::json(['error' => ['code' => 'REPLAYED_REQUEST', 'message' => 'Replayed.']], 401);
    }

    /** @param list<mixed> $responses */
    private function pipeline(array $responses, ?FakeHttpClient &$fake = null): Pipeline
    {
        $this->now = self::NOW;
        $this->waits = [];
        $fake = new FakeHttpClient(['*' => $responses]);
        $clock = new Clock(fn (): int => $this->now);
        $sleep = function (int $microseconds): void {
            $this->waits[] = intdiv($microseconds, 1_000_000);
            $this->now += intdiv($microseconds, 1_000_000);
        };

        return new Pipeline($fake, new SignMiddleware('key', new Signer('secret'), null, $clock), new RetryMiddleware($sleep, $clock));
    }

    private function request(string $method, ?int $attempts = 3): Request
    {
        $body = $method === 'GET' ? null : new RawBody('{}');

        return new Request($method, 'https://api.test.com/v1/verifications', '/v1/verifications', ['Idempotency-Key' => 'k-1'], $body, RetryPolicy::interactive($attempts, 0), 30, idempotencyKeyed: true);
    }

    public function test_a_replayed_post_is_resent_once_with_a_new_timestamp_and_signature_and_the_same_key(): void
    {
        $pipeline = $this->pipeline([self::replayed(), FakeHttpClient::json(['id' => 'ver_1'], 201)], $fake);

        $response = $pipeline->send($this->request('POST'));

        $this->assertSame(201, $response->status());
        [$first, $second] = $fake->sent();
        $this->assertSame((string) self::NOW, $first->header('X-Timestamp'));
        $this->assertSame((string) (self::NOW + 1), $second->header('X-Timestamp'));
        $this->assertNotSame($first->header('X-HMAC-Signature'), $second->header('X-HMAC-Signature'));
        $this->assertSame(TimestampedSignature::forRequest($second, 'POST/v1/verifications{}', 'secret'), $second->header('X-HMAC-Signature'));
        $this->assertSame('k-1', $second->header('Idempotency-Key'));
        $this->assertSame([1], $this->waits, 'It waits for the next whole second of the clock.');
    }

    public function test_it_applies_where_the_policy_would_not_retry_and_does_not_use_an_attempt(): void
    {
        // A single attempt, and a POST without a key: the policy retries nothing.
        $pipeline = $this->pipeline([self::replayed(), FakeHttpClient::json(['ok' => true])], $fake);

        $this->assertSame(200, $pipeline->send($this->request('POST', 1))->status());
        $fake->assertSentCount(2);
    }

    public function test_a_second_replayed_request_is_returned_not_resent(): void
    {
        $pipeline = $this->pipeline([self::replayed(), self::replayed(), FakeHttpClient::json(['ok' => true])], $fake);

        $response = $pipeline->send($this->request('POST'));

        $this->assertSame(401, $response->status());
        $fake->assertSentCount(2);
    }

    public function test_the_resend_is_in_addition_to_the_retry_attempts(): void
    {
        $pipeline = $this->pipeline([self::replayed(), FakeHttpClient::json(['message' => 'down'], 503), FakeHttpClient::json(['id' => 'ver_1'], 201)], $fake);

        $this->assertSame(201, $pipeline->send($this->request('POST'))->status());
        $fake->assertSentCount(3);
    }

    public function test_it_does_not_wait_when_the_clock_has_already_moved_on(): void
    {
        $this->waits = [];
        $this->now = self::NOW;
        $fake = new FakeHttpClient(['*' => [self::replayed(), FakeHttpClient::json(['id' => 'ver_1'], 201)]]);
        $moving = new Pipeline($fake, new SignMiddleware('key', new Signer('secret'), null, fn (): int => $this->now++), new RetryMiddleware(function (int $us): void {
            $this->waits[] = intdiv($us, 1_000_000);
        }, fn (): int => $this->now));

        $moving->send($this->request('POST'));

        $this->assertSame([0], $this->waits);
        $this->assertNotSame($fake->sent()[0]->header('X-Timestamp'), $fake->sent()[1]->header('X-Timestamp'));
    }

    public function test_a_get_is_unaffected(): void
    {
        $pipeline = $this->pipeline([self::replayed(), FakeHttpClient::json(['ok' => true])], $fake);

        $response = $pipeline->send($this->request('GET'));

        $this->assertSame(401, $response->status());
        $fake->assertSentCount(1);
        $this->assertSame([], $this->waits);
    }

    public function test_another_401_is_not_resent(): void
    {
        $pipeline = $this->pipeline([FakeHttpClient::json(['error' => ['code' => 'INVALID_SIGNATURE', 'message' => 'No.']], 401), FakeHttpClient::json(['ok' => true])], $fake);

        $this->assertSame(401, $pipeline->send($this->request('POST'))->status());
        $fake->assertSentCount(1);
    }

    public function test_the_client_throws_the_second_replay(): void
    {
        $fake = new FakeHttpClient(['*' => [self::replayed(), self::replayed()]]);
        $client = new Client(['api_key' => 'k', 'secret_key' => 's', 'base_url' => 'https://api.test.com'], $fake, null, static fn (int $us) => null);

        try {
            $client->verifications()->create(['external_id' => 'u-1']);
            $this->fail('Expected a ProofAgeException.');
        } catch (ProofAgeException $e) {
            $this->assertSame(401, $e->getCode());
            $this->assertSame('REPLAYED_REQUEST', $e->getErrorCode());
        }

        $fake->assertSentCount(2);
        $this->assertSame($fake->sent()[0]->header('Idempotency-Key'), $fake->sent()[1]->header('Idempotency-Key'));
    }
}
