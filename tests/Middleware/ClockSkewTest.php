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

/**
 * A host clock more than 300 seconds off fails every timestamped call with 401
 * TIMESTAMP_OUT_OF_RANGE. The Date header of that answer gives the server's time; the client
 * adopts it and resends once. And a re-attempt never signs the second of the attempt before.
 */
class ClockSkewTest extends TestCase
{
    private const NOW = 1795049078;

    private const SKEW = 1000;

    private int $now = self::NOW;

    /** @var list<int> microseconds waited */
    private array $waits = [];

    private static function outOfRange(?int $serverTime = self::NOW + self::SKEW): Response
    {
        return FakeHttpClient::json(
            ['error' => ['code' => 'TIMESTAMP_OUT_OF_RANGE', 'message' => 'Clock.']],
            401,
            $serverTime === null ? [] : ['Date' => gmdate('D, d M Y H:i:s', $serverTime).' GMT'],
        );
    }

    /** @param list<mixed> $responses */
    private function pipeline(array $responses, ?FakeHttpClient &$fake = null): Pipeline
    {
        $this->now = self::NOW;
        $this->waits = [];
        $fake = new FakeHttpClient(['*' => $responses]);
        $clock = new Clock(fn (): int => $this->now);
        $sleep = function (int $microseconds): void {
            $this->waits[] = $microseconds;
            $this->now += intdiv($microseconds, 1_000_000);
        };

        return new Pipeline($fake, new SignMiddleware('key', new Signer('secret'), null, $clock), new RetryMiddleware($sleep, $clock));
    }

    private function request(string $method, int $attempts = 3, bool $keyed = false): Request
    {
        $body = $method === 'GET' ? null : new RawBody('{}');

        return new Request($method, 'https://api.test.com/v1/verifications', '/v1/verifications', $keyed ? ['Idempotency-Key' => 'k-1'] : [], $body, RetryPolicy::interactive($attempts, 0), 30, idempotencyKeyed: $keyed);
    }

    public function test_a_skewed_clock_is_corrected_from_the_date_header_and_the_request_resent_once(): void
    {
        $pipeline = $this->pipeline([self::outOfRange(), FakeHttpClient::json(['id' => 'ver_1'], 201)], $fake);

        $response = $pipeline->send($this->request('POST', keyed: true));

        $this->assertSame(201, $response->status());
        [$first, $second] = $fake->sent();
        $this->assertSame((string) self::NOW, $first->header('X-Timestamp'));
        $this->assertSame((string) (self::NOW + self::SKEW), $second->header('X-Timestamp'));
        $this->assertNotSame($first->header('X-HMAC-Signature'), $second->header('X-HMAC-Signature'));
        $this->assertSame('k-1', $second->header('Idempotency-Key'));
        $this->assertSame([], $this->waits, 'The refused request was never accepted, so there is nothing to wait out.');
    }

    public function test_the_offset_is_kept_for_the_later_requests_of_the_client(): void
    {
        $pipeline = $this->pipeline([self::outOfRange(), FakeHttpClient::json(['ok' => true]), FakeHttpClient::json(['ok' => true])], $fake);

        $pipeline->send($this->request('GET'));
        $pipeline->send($this->request('GET'));

        $this->assertSame((string) (self::NOW + self::SKEW), $fake->sent()[2]->header('X-Timestamp'));
        $fake->assertSentCount(3);
    }

    public function test_a_get_is_corrected_too_and_outside_the_retry_attempts(): void
    {
        $pipeline = $this->pipeline([self::outOfRange(), FakeHttpClient::json(['ok' => true])], $fake);

        $this->assertSame(200, $pipeline->send($this->request('GET', 1))->status());
        $fake->assertSentCount(2);
    }

    public function test_without_a_date_header_it_is_returned_not_resent(): void
    {
        $pipeline = $this->pipeline([self::outOfRange(null), FakeHttpClient::json(['ok' => true])], $fake);

        $this->assertSame(401, $pipeline->send($this->request('POST'))->status());
        $fake->assertSentCount(1);
    }

    public function test_an_unparseable_date_header_is_returned_not_resent(): void
    {
        $bad = FakeHttpClient::json(['error' => ['code' => 'TIMESTAMP_OUT_OF_RANGE', 'message' => 'Clock.']], 401, ['Date' => 'not a date']);
        $pipeline = $this->pipeline([$bad, FakeHttpClient::json(['ok' => true])], $fake);

        $this->assertSame(401, $pipeline->send($this->request('POST'))->status());
        $fake->assertSentCount(1);
    }

    public function test_a_second_refusal_is_returned(): void
    {
        $pipeline = $this->pipeline([self::outOfRange(), self::outOfRange(), FakeHttpClient::json(['ok' => true])], $fake);

        $this->assertSame(401, $pipeline->send($this->request('POST'))->status());
        $fake->assertSentCount(2);
    }

    public function test_the_client_throws_the_second_refusal_and_shares_the_offset_with_its_signer(): void
    {
        $serverTime = time() + self::SKEW;
        $fake = new FakeHttpClient(['*' => [self::outOfRange($serverTime), self::outOfRange($serverTime), FakeHttpClient::json(['id' => 'ws_1'])]]);
        $client = new Client(['api_key' => 'k', 'secret_key' => 's', 'base_url' => 'https://api.test.com'], $fake, null, static fn (int $us) => null);

        try {
            $client->workspace()->get();
            $this->fail('Expected a ProofAgeException.');
        } catch (ProofAgeException $e) {
            $this->assertSame('TIMESTAMP_OUT_OF_RANGE', $e->getErrorCode());
        }

        $client->workspace()->get();

        $this->assertEqualsWithDelta(time() + self::SKEW, (int) $fake->sent()[2]->header('X-Timestamp'), 5);
    }

    public function test_a_retry_of_a_post_waits_until_the_clock_is_past_the_previous_timestamp(): void
    {
        $pipeline = $this->pipeline([FakeHttpClient::json(['message' => 'down'], 503), FakeHttpClient::json(['id' => 'ver_1'], 201)], $fake);

        $pipeline->send($this->request('POST', keyed: true));

        $this->assertSame([1_000_000], $this->waits);
        $this->assertSame((string) self::NOW, $fake->sent()[0]->header('X-Timestamp'));
        $this->assertSame((string) (self::NOW + 1), $fake->sent()[1]->header('X-Timestamp'));
    }

    public function test_a_retry_after_a_timeout_waits_for_the_next_second_too(): void
    {
        $pipeline = $this->pipeline([FakeHttpClient::timeout(), FakeHttpClient::json(['id' => 'ver_1'], 201)], $fake);

        $pipeline->send($this->request('POST', keyed: true));

        $this->assertSame([1_000_000], $this->waits);
        $this->assertNotSame($fake->sent()[0]->header('X-HMAC-Signature'), $fake->sent()[1]->header('X-HMAC-Signature'));
    }

    public function test_a_longer_delay_is_not_stretched(): void
    {
        $fake = new FakeHttpClient(['*' => [FakeHttpClient::json(['message' => 'down'], 503), FakeHttpClient::json(['id' => 'ver_1'], 201)]]);
        $clock = new Clock(fn (): int => $this->now);
        $this->now = self::NOW;
        $this->waits = [];
        $pipeline = new Pipeline($fake, new SignMiddleware('key', new Signer('secret'), null, $clock), new RetryMiddleware(function (int $us): void {
            $this->waits[] = $us;
        }, $clock));
        $request = new Request('POST', 'https://api.test.com/v1/verifications', '/v1/verifications', [], new RawBody('{}'), RetryPolicy::interactive(3, 3000), 30, idempotencyKeyed: true);

        $pipeline->send($request);

        $this->assertSame([3_000_000], $this->waits, 'One sleep, the longer of the delay and the wait for the next second.');
    }

    public function test_a_get_retry_is_not_made_to_wait_for_a_new_second(): void
    {
        $pipeline = $this->pipeline([FakeHttpClient::json(['message' => 'down'], 503), FakeHttpClient::json(['ok' => true])], $fake);

        $pipeline->send($this->request('GET'));

        $this->assertSame([0], $this->waits);
    }
}
