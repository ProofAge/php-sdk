<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProofAge\Sdk\Client;
use ProofAge\Sdk\Events\RequestEvent;
use ProofAge\Sdk\Exceptions\ProofAgeException;
use ProofAge\Sdk\Http\Request;
use ProofAge\Sdk\Http\Response;
use ProofAge\Sdk\Signing\Signer;
use ProofAge\Sdk\Testing\FakeHttpClient;

/**
 * X-ProofAge-Sdk and User-Agent as the transport receives them: after the user middleware,
 * on every attempt, for every kind of request the client builds.
 */
class SdkIdentificationTest extends TestCase
{
    private const CONFIG = [
        'api_key' => 'test-api-key',
        'secret_key' => 'test-secret-key',
        'base_url' => 'https://api.test.com',
        'retry_delay' => 0,
    ];

    /** @param array<string, mixed> $config */
    private function client(array $config = [], ?FakeHttpClient &$fake = null, mixed $response = null): Client
    {
        $fake = new FakeHttpClient(['*' => $response ?? FakeHttpClient::json(['ok' => true])]);

        return new Client(array_merge(self::CONFIG, $config), $fake);
    }

    private static function ownToken(): string
    {
        return 'php/'.Client::VERSION;
    }

    private static function ownUserAgent(): string
    {
        return 'ProofAge-PHP/'.Client::VERSION.' (PHP '.PHP_VERSION.')';
    }

    private function onlySent(FakeHttpClient $fake): Request
    {
        $this->assertCount(1, $fake->sent());

        return $fake->sent()[0];
    }

    public function test_the_version_constant_is_a_semantic_version(): void
    {
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', Client::VERSION);
        $this->assertSame('X-ProofAge-Sdk', Client::SDK_HEADER);
    }

    public function test_a_json_request_carries_the_sdk_header_and_user_agent(): void
    {
        $this->client([], $fake)->makeRequest('POST', 'verifications', ['external_id' => 'u-1']);

        $sent = $this->onlySent($fake);
        $this->assertSame(self::ownToken(), $sent->header('X-ProofAge-Sdk'));
        $this->assertSame(self::ownUserAgent(), $sent->header('User-Agent'));
    }

    public function test_a_bodyless_request_carries_the_sdk_header(): void
    {
        $this->client([], $fake)->workspace()->get();

        $this->assertSame(self::ownToken(), $this->onlySent($fake)->header('X-ProofAge-Sdk'));
    }

    public function test_a_multipart_request_carries_the_sdk_header(): void
    {
        $path = sys_get_temp_dir().'/proofage-sdk-header-'.uniqid().'.jpg';
        file_put_contents($path, 'jpeg-bytes');

        try {
            $this->client([], $fake, FakeHttpClient::raw(''))->verifications('ver_1')->uploadMedia(['type' => 'selfie', 'file' => $path]);
        } finally {
            unlink($path);
        }

        $this->assertSame(self::ownToken(), $this->onlySent($fake)->header('X-ProofAge-Sdk'));
    }

    public function test_a_media_download_carries_the_sdk_header(): void
    {
        $this->client([], $fake, FakeHttpClient::raw('bytes', 200, ['Content-Type' => 'image/jpeg']))
            ->verifications('ver_1')->downloadMedia('med_1');

        $this->assertSame(self::ownToken(), $this->onlySent($fake)->header('X-ProofAge-Sdk'));
        $this->assertSame(self::ownUserAgent(), $fake->sent()[0]->header('User-Agent'));
    }

    public function test_every_retry_attempt_carries_the_sdk_header(): void
    {
        $this->client(['retry_attempts' => 3], $fake, [
            FakeHttpClient::json([], 503),
            FakeHttpClient::json([], 503),
            FakeHttpClient::json(['ok' => true]),
        ])->workspace()->get();

        $this->assertCount(3, $fake->sent());

        foreach ($fake->sent() as $request) {
            $this->assertSame(self::ownToken(), $request->header('X-ProofAge-Sdk'));
        }
    }

    public function test_a_wrapper_prepends_its_tokens_outermost_first(): void
    {
        $this->client(['sdk_tokens' => ['acme-shop/2.1.0', 'laravel/0.9.0']], $fake)->workspace()->get();

        $this->assertSame('acme-shop/2.1.0 laravel/0.9.0 '.self::ownToken(), $this->onlySent($fake)->header('X-ProofAge-Sdk'));
    }

    public function test_a_wrapper_prepends_its_products_to_the_user_agent(): void
    {
        $this->client(['user_agent_prefix' => 'ProofAge-Laravel/0.9.0'], $fake)->workspace()->get();

        $this->assertSame('ProofAge-Laravel/0.9.0 '.self::ownUserAgent(), $this->onlySent($fake)->header('User-Agent'));
    }

    public function test_an_empty_token_list_and_prefix_change_nothing(): void
    {
        $this->client(['sdk_tokens' => [], 'user_agent_prefix' => ''], $fake)->workspace()->get();

        $sent = $this->onlySent($fake);
        $this->assertSame(self::ownToken(), $sent->header('X-ProofAge-Sdk'));
        $this->assertSame(self::ownUserAgent(), $sent->header('User-Agent'));
    }

    /** @return iterable<string, array{callable(Request, callable(Request): Response): Response}> */
    public static function tamperingMiddleware(): iterable
    {
        yield 'replaced' => [static fn (Request $r, callable $next): Response => $next($r->withHeader('X-ProofAge-Sdk', 'other/1.0.0'))];
        yield 'replaced, other spelling' => [static fn (Request $r, callable $next): Response => $next($r->withHeader('x-proofage-sdk', 'other/1.0.0'))];
        yield 'removed' => [static fn (Request $r, callable $next): Response => $next($r->withoutHeader('X-ProofAge-Sdk'))];
        yield 'emptied' => [static fn (Request $r, callable $next): Response => $next($r->withHeader('X-ProofAge-Sdk', ''))];
    }

    /** @param callable(Request, callable(Request): Response): Response $middleware */
    #[DataProvider('tamperingMiddleware')]
    public function test_middleware_cannot_drop_or_replace_the_sdk_token(callable $middleware): void
    {
        $client = $this->client(['sdk_tokens' => ['acme/1.0.0']], $fake);
        $client->pushMiddleware($middleware);

        $client->workspace()->get();

        $sent = $this->onlySent($fake);
        $this->assertSame('acme/1.0.0 '.self::ownToken(), $sent->header('X-ProofAge-Sdk'));
        $this->assertCount(1, array_filter(array_keys($sent->headers), static fn (string $name): bool => strcasecmp($name, 'X-ProofAge-Sdk') === 0));
    }

    public function test_a_user_agent_set_by_the_caller_is_kept(): void
    {
        $client = $this->client([], $fake);
        $client->pushMiddleware(static fn (Request $r, callable $next): Response => $next($r->withHeader('User-Agent', 'MyApp/3.0')));

        $client->workspace()->get();

        $sent = $this->onlySent($fake);
        $this->assertSame('MyApp/3.0', $sent->header('User-Agent'));
        $this->assertSame(self::ownToken(), $sent->header('X-ProofAge-Sdk'), 'The SDK header is sent whatever the User-Agent is.');
    }

    public function test_a_user_agent_removed_by_middleware_is_put_back(): void
    {
        $client = $this->client([], $fake);
        $client->pushMiddleware(static fn (Request $r, callable $next): Response => $next($r->withoutHeader('User-Agent')));

        $client->workspace()->get();

        $this->assertSame(self::ownUserAgent(), $this->onlySent($fake)->header('User-Agent'));
    }

    public function test_the_request_event_sees_the_headers_that_are_sent(): void
    {
        $client = $this->client(['sdk_tokens' => ['acme/1.0.0']], $fake);
        $seen = null;
        $client->onRequest(static function (RequestEvent $event) use (&$seen): void {
            $seen = $event->headers()['X-ProofAge-Sdk'] ?? null;
        });

        $client->workspace()->get();

        $this->assertSame('acme/1.0.0 '.self::ownToken(), $seen);
    }

    public function test_the_headers_are_not_part_of_the_signature(): void
    {
        $this->client([], $plain)->makeRequest('POST', 'verifications', ['external_id' => 'u-1']);
        $this->client(['sdk_tokens' => ['acme/1.0.0'], 'user_agent_prefix' => 'Acme/1.0.0'], $wrapped)->makeRequest('POST', 'verifications', ['external_id' => 'u-1']);

        $expected = (new Signer('test-secret-key'))->sign($plain->sent()[0]->withoutHeader('X-ProofAge-Sdk')->withoutHeader('User-Agent'));

        $this->assertSame(hash_hmac('sha256', 'POST/v1/verifications{"external_id":"u-1"}', 'test-secret-key'), $expected);
        $this->assertSame($expected, $plain->sent()[0]->header('X-HMAC-Signature'));
        $this->assertSame($expected, $wrapped->sent()[0]->header('X-HMAC-Signature'));
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidSdkTokens(): iterable
    {
        yield 'not a list' => ['laravel/0.9.0'];
        yield 'keyed' => [['laravel' => 'laravel/0.9.0']];
        yield 'not a string' => [[42]];
        yield 'no version' => [['laravel']];
        yield 'empty version' => [['laravel/']];
        yield 'uppercase name' => [['Laravel/0.9.0']];
        yield 'space inside' => [['laravel/0.9.0 php/9.9.9']];
        yield 'header injection' => [["laravel/0.9.0\r\nX-Evil: 1"]];
        yield 'two slashes' => [['laravel/0.9/0']];
        yield 'empty string' => [['']];
    }

    #[DataProvider('invalidSdkTokens')]
    public function test_an_invalid_sdk_token_is_a_configuration_error(mixed $tokens): void
    {
        $this->expectException(ProofAgeException::class);
        $this->expectExceptionMessage('sdk_tokens');

        $this->client(['sdk_tokens' => $tokens]);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidUserAgentPrefixes(): iterable
    {
        yield 'not a string' => [['ProofAge-Laravel/0.9.0']];
        yield 'header injection' => ["ProofAge-Laravel/0.9.0\r\nX-Evil: 1"];
        yield 'leading space' => [' ProofAge-Laravel/0.9.0'];
        yield 'double space' => ['A/1  B/2'];
        yield 'comment' => ['ProofAge-Laravel/0.9.0 (Laravel 13)'];
    }

    #[DataProvider('invalidUserAgentPrefixes')]
    public function test_an_invalid_user_agent_prefix_is_a_configuration_error(mixed $prefix): void
    {
        $this->expectException(ProofAgeException::class);
        $this->expectExceptionMessage('user_agent_prefix');

        $this->client(['user_agent_prefix' => $prefix]);
    }
}
