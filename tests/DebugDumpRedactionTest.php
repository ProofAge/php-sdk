<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Tests;

use PHPUnit\Framework\TestCase;
use ProofAge\Sdk\Client;
use ProofAge\Sdk\Exceptions\ProofAgeException;
use ProofAge\Sdk\Exceptions\TransportException;
use ProofAge\Sdk\Http\Body\FilePart;
use ProofAge\Sdk\Http\Body\MultipartBody;
use ProofAge\Sdk\Http\Body\RawBody;
use ProofAge\Sdk\Http\Request;
use ProofAge\Sdk\Http\Response;
use ProofAge\Sdk\Http\RetryPolicy;
use ProofAge\Sdk\Middleware\SignMiddleware;
use ProofAge\Sdk\Signing\Signer;
use ProofAge\Sdk\Testing\FakeHttpClient;
use ProofAge\Sdk\Webhooks\WebhookSignatureVerifier;
use ProofAge\Sdk\Webhooks\WebhookVerifier;

/**
 * `error_log(print_r($e, true))` in a catch block is routine in plain-PHP shops. Nothing
 * print_r() or var_dump() can reach from a client, a request, a response or an SDK
 * exception may contain the secret key, the plaintext API key, or uploaded file bytes.
 *
 * Two routes are closed: __debugInfo() on every object that holds a secret or a body, and
 * closures in the pipeline that are not bound to an object holding the signer (with
 * zend.exception_ignore_args=0, PHP's default, a trace carries frame arguments and a
 * bound closure carries its $this).
 */
class DebugDumpRedactionTest extends TestCase
{
    private const SECRET = 'sk_live_9f8e7d6c5b4a3210';

    private const API_KEY = 'pk_live_0123456789abcdef';

    private const FILE_BYTES = "\xFF\xD8selfie-jpeg-bytes-that-must-never-be-logged";

    private string $ignoreArgs;

    protected function setUp(): void
    {
        $this->ignoreArgs = (string) ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
    }

    protected function tearDown(): void
    {
        ini_set('zend.exception_ignore_args', $this->ignoreArgs);
    }

    /** @param array<string, mixed> $routes */
    private function client(array $routes, ?FakeHttpClient &$fake = null): Client
    {
        $fake = new FakeHttpClient($routes);

        return new Client([
            'api_key' => self::API_KEY,
            'secret_key' => self::SECRET,
            'base_url' => 'https://api.test.com',
            'retry_attempts' => 1,
        ], $fake);
    }

    /** @return list<string> the print_r() and var_dump() renderings */
    private static function dumps(mixed $value): array
    {
        ob_start();
        var_dump($value);
        $varDump = (string) ob_get_clean();

        return [print_r($value, true), $varDump];
    }

    private function assertNoSecretIn(mixed $value, string ...$alsoAbsent): void
    {
        foreach (self::dumps($value) as $dump) {
            $this->assertStringNotContainsString(self::SECRET, $dump);
            $this->assertStringNotContainsString(self::API_KEY, $dump);

            foreach ($alsoAbsent as $needle) {
                $this->assertStringNotContainsString($needle, $dump);
            }
        }
    }

    public function test_a_client_dump_shows_the_config_with_the_keys_masked(): void
    {
        $client = $this->client([]);

        $this->assertNoSecretIn($client);

        $dump = print_r($client, true);
        $this->assertStringContainsString('****cdef', $dump, 'The API key is masked to its last four characters, as the events mask it.');
        $this->assertStringContainsString('[redacted]', $dump);
        $this->assertStringContainsString('https://api.test.com', $dump, 'Non-secret config stays visible.');
    }

    public function test_the_signer_and_the_sign_middleware_never_show_the_secret(): void
    {
        $signer = new Signer(self::SECRET);
        $middleware = new SignMiddleware(self::API_KEY, $signer);

        $this->assertNoSecretIn($signer);
        $this->assertNoSecretIn($middleware);
    }

    public function test_a_request_dump_masks_the_auth_headers_and_summarises_the_body(): void
    {
        $request = new Request(
            'POST',
            'https://api.test.com/v1/verifications/ver_1/media',
            '/v1/verifications/ver_1/media',
            ['Accept' => 'application/json', 'X-API-Key' => self::API_KEY, 'X-HMAC-Signature' => str_repeat('4c6daa63', 8)],
            new MultipartBody(['type' => 'selfie'], [new FilePart('file', 'selfie.jpg', self::FILE_BYTES)]),
            RetryPolicy::interactive(),
            30,
        );

        $this->assertNoSecretIn($request, self::FILE_BYTES, str_repeat('4c6daa63', 8));

        $dump = print_r($request, true);
        $this->assertStringContainsString('****cdef', $dump);
        $this->assertStringContainsString('4c6daa63...', $dump);
        $this->assertStringContainsString(hash('sha256', self::FILE_BYTES), $dump, 'A file is shown as its size and hash.');
        $this->assertStringContainsString('selfie.jpg', $dump);
        $this->assertStringContainsString('/v1/verifications/ver_1/media', $dump);
    }

    public function test_raw_body_and_file_part_dumps_carry_size_and_hash_instead_of_bytes(): void
    {
        $json = '{"external_metadata":{"name":"Jürgen","dob":"1990-01-15"}}';

        $this->assertNoSecretIn(new RawBody($json), '1990-01-15');
        $this->assertNoSecretIn(new FilePart('file', 'front.jpg', self::FILE_BYTES), self::FILE_BYTES);

        $this->assertStringContainsString(hash('sha256', $json), print_r(new RawBody($json), true));
        $this->assertStringContainsString((string) strlen($json), print_r(new RawBody($json), true));
    }

    public function test_the_webhook_verifiers_never_show_the_secret(): void
    {
        $this->assertNoSecretIn(new WebhookVerifier(self::API_KEY, self::SECRET));
        $this->assertNoSecretIn(new WebhookSignatureVerifier(self::SECRET));
    }

    public function test_a_transport_exception_caught_from_a_client_call_dumps_clean_with_trace_args_on(): void
    {
        $client = $this->client(['*' => FakeHttpClient::failedConnection('Connection refused')]);

        try {
            $client->makeRequest('POST', 'verifications', ['external_id' => 'user-42']);
            $this->fail('Expected a TransportException.');
        } catch (TransportException $e) {
            $this->assertSame('0', ini_get('zend.exception_ignore_args'), 'Frame arguments must be in the trace for this test to mean anything.');
            $this->assertNotEmpty($e->getTrace());
            $this->assertNoSecretIn($e);
        }
    }

    public function test_an_http_error_exception_holding_the_request_dumps_without_key_or_file_bytes(): void
    {
        $path = sys_get_temp_dir().'/proofage-dump-'.uniqid().'.jpg';
        file_put_contents($path, self::FILE_BYTES);
        $client = $this->client(['*' => FakeHttpClient::json(['error' => ['code' => 'MEDIA_NOT_FOUND', 'message' => 'nope']], 404)]);

        try {
            $client->makeRequest('POST', 'verifications/ver_1/media', ['type' => 'selfie'], ['file' => $path]);
            $this->fail('Expected a ProofAgeException.');
        } catch (ProofAgeException $e) {
            $this->assertNotNull($e->getResponse());
            $this->assertNoSecretIn($e, self::FILE_BYTES);
            $this->assertNoSecretIn($e->getResponse(), self::FILE_BYTES);
        } finally {
            unlink($path);
        }
    }

    public function test_a_configuration_failure_does_not_leave_the_config_in_the_trace(): void
    {
        if (PHP_VERSION_ID < 80200) {
            $this->markTestSkipped('#[\SensitiveParameter] redacts trace arguments from PHP 8.2; on 8.1 zend.exception_ignore_args=1 is the only protection.');
        }

        try {
            new Client(['api_key' => self::API_KEY, 'secret_key' => self::SECRET, 'base_url' => '']);
            $this->fail('Expected a ProofAgeException.');
        } catch (ProofAgeException $e) {
            $this->assertSame('Base URL is required', $e->getMessage());
            $this->assertNoSecretIn($e);
        }
    }

    public function test_pipeline_closures_are_not_bound_to_an_object_that_holds_the_signer(): void
    {
        $client = $this->client(['*' => FakeHttpClient::json([])]);
        $seen = [];

        $client->pushMiddleware(static function (Request $request, callable $next) use (&$seen): Response {
            $seen[] = $next;

            return $next($request);
        });
        $client->makeRequest('GET', 'workspace');

        $this->assertCount(1, $seen);

        foreach ($seen as $next) {
            $this->assertInstanceOf(\Closure::class, $next);
            $this->assertNull((new \ReflectionFunction($next))->getClosureThis(), 'A $next handed to middleware must not carry $this, or a trace argument reaches the signer through it.');
            $this->assertNoSecretIn($next);
        }
    }

    public function test_the_pipeline_still_works_end_to_end_after_unbinding(): void
    {
        $client = $this->client(['*' => FakeHttpClient::json(['ok' => true])], $fake);

        $this->assertSame(['ok' => true], $client->makeRequest('GET', 'workspace')->json());
        $this->assertSame(self::API_KEY, $fake->sent()[0]->header('X-API-Key'));
    }
}
