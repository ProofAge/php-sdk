<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProofAge\Sdk\Client;
use ProofAge\Sdk\Events\ErrorEvent;
use ProofAge\Sdk\Events\RequestEvent;
use ProofAge\Sdk\Events\ResponseEvent;
use ProofAge\Sdk\Exceptions\AuthenticationException;
use ProofAge\Sdk\Exceptions\ExceptionFactory;
use ProofAge\Sdk\Exceptions\ProofAgeException;
use ProofAge\Sdk\Exceptions\TransportException;
use ProofAge\Sdk\Exceptions\ValidationException;
use ProofAge\Sdk\Http\Body\FilePart;
use ProofAge\Sdk\Http\Body\MultipartBody;
use ProofAge\Sdk\Http\Body\RawBody;
use ProofAge\Sdk\Http\Curl\CurlHttpClient;
use ProofAge\Sdk\Http\Request;
use ProofAge\Sdk\Http\Response;
use ProofAge\Sdk\Signing\Signer;
use ProofAge\Sdk\Testing\FakeHttpClient;

class ClientTest extends TestCase
{
    private const CONFIG = [
        'api_key' => 'test-api-key',
        'secret_key' => 'test-secret-key',
        'base_url' => 'https://api.test.com',
        'version' => 'v1',
    ];

    /**
     * @param  array<string, mixed>  $routes
     * @param  array<string, mixed>  $config
     */
    private function client(array $routes, array $config = [], ?FakeHttpClient &$fake = null): Client
    {
        $fake = new FakeHttpClient($routes);

        return new Client(array_merge(self::CONFIG, $config), $fake);
    }

    private function signature(string $canonical): string
    {
        return hash_hmac('sha256', $canonical, 'test-secret-key');
    }

    public function test_it_throws_exception_when_api_key_is_missing(): void
    {
        $this->expectException(ProofAgeException::class);
        $this->expectExceptionMessage('API key is required');

        new Client(['secret_key' => 'test-secret-key', 'base_url' => 'https://api.test.com']);
    }

    public function test_it_throws_exception_when_secret_key_is_missing(): void
    {
        $this->expectException(ProofAgeException::class);
        $this->expectExceptionMessage('Secret key is required');

        new Client(['api_key' => 'test-api-key', 'base_url' => 'https://api.test.com']);
    }

    public function test_it_throws_exception_when_base_url_is_missing(): void
    {
        $this->expectException(ProofAgeException::class);
        $this->expectExceptionMessage('Base URL is required');

        new Client(['api_key' => 'test-api-key', 'secret_key' => 'test-secret-key']);
    }

    public function test_an_empty_string_counts_as_missing(): void
    {
        $this->expectException(ProofAgeException::class);
        $this->expectExceptionMessage('API key is required');

        new Client(['api_key' => '', 'secret_key' => 'test-secret-key', 'base_url' => 'https://api.test.com']);
    }

    public function test_the_default_transport_is_curl_and_an_injected_one_is_returned_as_is(): void
    {
        $this->assertInstanceOf(CurlHttpClient::class, (new Client(self::CONFIG))->transport());

        $fake = new FakeHttpClient;
        $this->assertSame($fake, (new Client(self::CONFIG, $fake))->transport());
    }

    public function test_get_builds_url_path_headers_and_signature_with_no_body(): void
    {
        $client = $this->client(['api.test.com/v1/workspace' => FakeHttpClient::json(['id' => 'ws_1'])], [], $fake);

        $response = $client->makeRequest('GET', 'workspace');

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(['id' => 'ws_1'], $response->json());

        $sent = $fake->sent()[0];
        $this->assertSame('GET', $sent->method);
        $this->assertSame('https://api.test.com/v1/workspace', $sent->url);
        $this->assertSame('/v1/workspace', $sent->path);
        $this->assertNull($sent->body);
        $this->assertSame('application/json', $sent->header('Accept'));
        $this->assertNull($sent->header('Content-Type'));
        $this->assertSame('test-api-key', $sent->header('X-API-Key'));
        $this->assertSame($this->signature('GET/v1/workspace'), $sent->header('X-HMAC-Signature'));
        $this->assertSame(30, $sent->timeout);
        $this->assertSame(3, $sent->retryPolicy->maxAttempts);
        $this->assertSame(1000, $sent->retryPolicy->delayMs);
        $this->assertFalse($sent->stream);
        $this->assertNull($sent->sink);
    }

    public function test_base_url_and_endpoint_slashes_and_the_version_are_normalized(): void
    {
        $client = $this->client(['*' => FakeHttpClient::json([])], ['base_url' => 'https://api.test.com/', 'version' => 'v2'], $fake);

        $client->makeRequest('get', '/verifications/ver_1');

        $sent = $fake->sent()[0];
        $this->assertSame('GET', $sent->method);
        $this->assertSame('https://api.test.com/v2/verifications/ver_1', $sent->url);
        $this->assertSame('/v2/verifications/ver_1', $sent->path);
    }

    public function test_version_defaults_to_v1(): void
    {
        $config = self::CONFIG;
        unset($config['version']);
        $fake = new FakeHttpClient(['*' => FakeHttpClient::json([])]);

        (new Client($config, $fake))->makeRequest('GET', 'workspace');

        $this->assertSame('https://api.test.com/v1/workspace', $fake->sent()[0]->url);
    }

    public function test_post_serialises_once_and_signs_the_exact_bytes_it_sends(): void
    {
        // Ported from proofage-laravel-client tests/VerificationResourceTest.php:426-451.
        $client = $this->client(['api.test.com/v1/verifications/ver_123/blocked-face' => FakeHttpClient::raw('', 204)], [], $fake);

        $response = $client->makeRequest('POST', 'verifications/ver_123/blocked-face', ['reason' => 'text here']);

        $this->assertNull($response->json());

        $expectedBody = json_encode(['reason' => 'text here']);
        $sent = $fake->sent()[0];
        $this->assertInstanceOf(RawBody::class, $sent->body);
        $this->assertSame($expectedBody, $sent->body->bytes);
        $this->assertSame('application/json', $sent->header('Content-Type'));
        $this->assertSame($this->signature('POST/v1/verifications/ver_123/blocked-face'.$expectedBody), $sent->header('X-HMAC-Signature'));
    }

    public function test_default_json_flags_escape_slashes_and_unicode(): void
    {
        $client = $this->client(['*' => FakeHttpClient::json([])], [], $fake);

        $client->makeRequest('POST', 'verifications', ['callback_url' => 'https://example.com/hook', 'external_metadata' => ['name' => 'Jürgen']]);

        $sent = $fake->sent()[0];
        $this->assertInstanceOf(RawBody::class, $sent->body);
        $this->assertSame('{"callback_url":"https:\/\/example.com\/hook","external_metadata":{"name":"J\u00fcrgen"}}', $sent->body->bytes);
    }

    public function test_empty_data_sends_no_body_and_signs_method_and_path_only(): void
    {
        $client = $this->client(['*' => FakeHttpClient::raw('', 204)], [], $fake);

        $client->makeRequest('POST', 'verifications/ver_123/submit', []);

        $sent = $fake->sent()[0];
        $this->assertNull($sent->body);
        $this->assertNull($sent->header('Content-Type'));
        $this->assertSame($this->signature('POST/v1/verifications/ver_123/submit'), $sent->header('X-HMAC-Signature'));
    }

    public function test_a_body_that_cannot_be_json_encoded_throws_before_anything_is_sent(): void
    {
        $client = $this->client(['*' => FakeHttpClient::json([])], [], $fake);

        try {
            $client->makeRequest('POST', 'verifications', ['external_metadata' => ['name' => "\xB1\x31"]]);
            $this->fail('Expected a ProofAgeException.');
        } catch (ProofAgeException $e) {
            $this->assertSame('Request body is not JSON-encodable', $e->getMessage());
            $this->assertInstanceOf(\JsonException::class, $e->getPrevious());
        }

        $fake->assertNothingSent();
    }

    /**
     * The signed path must be the path the transport sends. cURL percent-encodes a
     * non-ASCII segment, so signing it raw produced a 401 "HMAC signature is invalid"
     * where the developer should have seen a 404.
     */
    public function test_path_segments_are_percent_encoded_in_both_the_url_and_the_signed_path(): void
    {
        $client = $this->client(['*' => FakeHttpClient::json([])], [], $fake);

        $client->makeRequest('GET', 'verifications/Jürgen:1+2,x/document');

        $sent = $fake->sent()[0];
        $this->assertSame('https://api.test.com/v1/verifications/J%C3%BCrgen%3A1%2B2%2Cx/document', $sent->url);
        $this->assertSame('/v1/verifications/J%C3%BCrgen%3A1%2B2%2Cx/document', $sent->path);
        $this->assertSame($this->signature('GET/v1/verifications/J%C3%BCrgen%3A1%2B2%2Cx/document'), $sent->header('X-HMAC-Signature'));
    }

    public function test_plain_segments_and_a_query_string_are_unaffected_by_the_encoding(): void
    {
        $client = $this->client(['*' => FakeHttpClient::json([])], [], $fake);

        $client->makeRequest('GET', '/verifications/0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6b/media/med_1?limit=10');

        $this->assertSame('https://api.test.com/v1/verifications/0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6b/media/med_1?limit=10', $fake->sent()[0]->url);
        $this->assertSame('/v1/verifications/0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6b/media/med_1?limit=10', $fake->sent()[0]->path);
    }

    /** @return iterable<string, array{string}> */
    public static function endpointsTheTransportWouldRewrite(): iterable
    {
        // cURL removes dot segments and strips fragments before sending; whitespace and
        // control characters are rewritten or rejected depending on the transport.
        yield 'parent segment' => ['verifications/../workspace'];
        yield 'current segment' => ['./workspace'];
        yield 'trailing dot segment' => ['verifications/.'];
        yield 'fragment' => ['workspace#frag'];
        yield 'fragment after query' => ['verifications?limit=1#frag'];
        yield 'space' => ['verifications/ver 1'];
        yield 'tab' => ["verifications/ver\t1"];
        yield 'newline' => ["verifications/ver\n1"];
        yield 'nul' => ["verifications/ver\x001"];
        yield 'delete' => ["verifications/ver\x7F1"];
    }

    #[DataProvider('endpointsTheTransportWouldRewrite')]
    public function test_an_endpoint_the_transport_would_rewrite_is_rejected_before_anything_is_sent(string $endpoint): void
    {
        $client = $this->client(['*' => FakeHttpClient::json([])], [], $fake);

        try {
            $client->makeRequest('GET', $endpoint);
            $this->fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('endpoint', strtolower($e->getMessage()));
        }

        try {
            $client->makeStreamedRequest('GET', $endpoint);
            $this->fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException) {
        }

        $fake->assertNothingSent();
    }

    public function test_a_query_string_is_normalized_in_the_url_and_the_signed_path(): void
    {
        $fake = new FakeHttpClient(['*' => FakeHttpClient::json([])]);
        $client = new Client(array_merge(self::CONFIG, ['secret_key' => 'vector-secret-2026']), $fake);

        $client->makeRequest('GET', 'verifications?status=approved&limit=10&q=a+b');

        $sent = $fake->sent()[0];
        $this->assertSame('https://api.test.com/v1/verifications?limit=10&q=a%20b&status=approved', $sent->url);
        $this->assertSame('/v1/verifications?limit=10&q=a%20b&status=approved', $sent->path);
        // The golden vector "query string is normalized" in resources/hmac-vectors.json.
        $this->assertSame('fd1aab9c759aeaa41b7a9958ebe4fb3f73014e00d6682e2956d877ca6d5db565', $sent->header('X-HMAC-Signature'));
    }

    public function test_files_make_a_multipart_request_signed_over_fields_and_file_hashes(): void
    {
        $path = sys_get_temp_dir().'/proofage-client-'.uniqid().'.jpg';
        file_put_contents($path, 'not-really-a-jpeg');
        $client = $this->client(['api.test.com/*' => FakeHttpClient::json(['message' => 'ok'])], [], $fake);

        try {
            $result = $client->makeRequest('POST', 'verifications/ver_123/media', ['type' => 'document', 'side' => 'front'], ['file' => $path]);
        } finally {
            unlink($path);
        }

        $this->assertSame(['message' => 'ok'], $result->json());

        $sent = $fake->sent()[0];
        $this->assertInstanceOf(MultipartBody::class, $sent->body);
        $this->assertSame(['type' => 'document', 'side' => 'front'], $sent->body->fields);
        $this->assertCount(1, $sent->body->files);
        $this->assertSame('file', $sent->body->files[0]->name);
        $this->assertSame(basename($path), $sent->body->files[0]->filename);
        $this->assertSame('not-really-a-jpeg', $sent->body->files[0]->contents);
        $this->assertNull($sent->header('Content-Type'), 'The transport sets the multipart content type with its boundary.');
        $this->assertSame(
            (new Signer('test-secret-key'))->signMultipart('POST', '/v1/verifications/ver_123/media', ['type' => 'document', 'side' => 'front'], [hash('sha256', 'not-really-a-jpeg')]),
            $sent->header('X-HMAC-Signature'),
        );
    }

    public function test_files_may_be_paths_spl_file_info_or_file_parts(): void
    {
        $path = sys_get_temp_dir().'/proofage-client-'.uniqid().'.jpg';
        file_put_contents($path, 'bytes');
        $client = $this->client(['*' => FakeHttpClient::json([])], [], $fake);

        try {
            $client->makeRequest('POST', 'verifications/ver_1/media', ['type' => 'selfie'], [
                'a' => $path,
                'b' => new \SplFileInfo($path),
                'c' => new FilePart('c', 'given.jpg', 'inline'),
            ]);
        } finally {
            unlink($path);
        }

        $sent = $fake->sent()[0];
        $this->assertInstanceOf(MultipartBody::class, $sent->body);
        $this->assertSame(['a', 'b', 'c'], array_map(static fn (FilePart $part): string => $part->name, $sent->body->files));
        $this->assertSame([basename($path), basename($path), 'given.jpg'], array_map(static fn (FilePart $part): string => $part->filename, $sent->body->files));
    }

    public function test_a_missing_file_throws_before_anything_is_sent(): void
    {
        $missing = sys_get_temp_dir().'/proofage-missing-'.uniqid().'.jpg';
        $client = $this->client(['*' => FakeHttpClient::json([])], [], $fake);

        try {
            $client->makeRequest('POST', 'verifications/ver_1/media', ['type' => 'selfie'], ['file' => $missing]);
            $this->fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame("File not found: {$missing}", $e->getMessage());
        }

        $fake->assertNothingSent();
    }

    public function test_an_unsupported_file_value_throws(): void
    {
        $client = $this->client(['*' => FakeHttpClient::json([])], [], $fake);

        $this->expectException(\InvalidArgumentException::class);

        $client->makeRequest('POST', 'verifications/ver_1/media', [], ['file' => 42]);
    }

    public function test_it_throws_authentication_exception_on_401(): void
    {
        $client = $this->client(['api.test.com/*' => FakeHttpClient::json(['error' => ['message' => 'Invalid API key']], 401)]);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid API key');

        $client->makeRequest('GET', 'workspace');
    }

    public function test_it_throws_validation_exception_on_422(): void
    {
        $client = $this->client(['api.test.com/*' => FakeHttpClient::json([
            'error' => ['message' => 'Validation failed'],
            'errors' => ['callback_url' => ['The callback url field is required.']],
        ], 422)]);

        try {
            $client->makeRequest('POST', 'verifications', ['x' => 1]);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame('Validation failed', $e->getMessage());
            $this->assertSame(422, $e->getCode());
            $this->assertSame(['callback_url' => ['The callback url field is required.']], $e->getErrors());
        }
    }

    public function test_other_statuses_throw_the_base_exception_with_the_status_as_code(): void
    {
        $client = $this->client(['api.test.com/*' => FakeHttpClient::json(['error' => ['code' => 'MEDIA_NOT_FOUND', 'message' => 'Media not found']], 404)]);

        try {
            $client->makeRequest('GET', 'verifications/ver_1/media/med_1');
            $this->fail('Expected a ProofAgeException.');
        } catch (ProofAgeException $e) {
            $this->assertSame(ProofAgeException::class, $e::class);
            $this->assertSame(404, $e->getCode());
            $this->assertSame('MEDIA_NOT_FOUND', $e->getErrorCode());
            $this->assertSame('Media not found', $e->getMessage());
            $this->assertNotNull($e->getResponse());
            $this->assertSame(404, $e->getResponse()->status());
        }
    }

    public function test_an_injected_exception_factory_decides_what_is_thrown(): void
    {
        $custom = new class('custom') extends ProofAgeException {};
        $factory = new class($custom) implements ExceptionFactory
        {
            public function __construct(private ProofAgeException $exception) {}

            public function fromResponse(Response $response): ProofAgeException
            {
                return $this->exception;
            }

            public function configuration(string $message, ?\Throwable $previous = null): ProofAgeException
            {
                return $this->exception;
            }
        };
        $client = new Client(self::CONFIG, new FakeHttpClient(['*' => FakeHttpClient::json([], 500)]), $factory);

        try {
            $client->makeRequest('GET', 'workspace');
            $this->fail('Expected the custom exception.');
        } catch (ProofAgeException $e) {
            $this->assertSame($custom, $e);
        }
    }

    public function test_streamed_request_without_a_sink_streams_with_the_download_policy(): void
    {
        $client = $this->client(['api.test.com/v1/verifications/ver_1/media/med_1' => FakeHttpClient::raw('binary-image-bytes', 200, ['Content-Type' => 'image/jpeg'])], ['download_retry_attempts' => 2, 'retry_delay' => 5], $fake);

        $response = $client->makeStreamedRequest('GET', 'verifications/ver_1/media/med_1');

        $this->assertSame('binary-image-bytes', (string) $response->getBody());
        $this->assertSame('image/jpeg', $response->header('content-type'));

        $sent = $fake->sent()[0];
        $this->assertSame('*/*', $sent->header('Accept'));
        $this->assertNull($sent->body);
        $this->assertTrue($sent->stream);
        $this->assertNull($sent->sink);
        $this->assertSame(2, $sent->retryPolicy->maxAttempts);
        $this->assertSame(5, $sent->retryPolicy->delayMs);
        $this->assertSame('test-api-key', $sent->header('X-API-Key'));
        $this->assertSame($this->signature('GET/v1/verifications/ver_1/media/med_1'), $sent->header('X-HMAC-Signature'));
    }

    public function test_streamed_request_with_a_sink_writes_the_file(): void
    {
        $path = sys_get_temp_dir().'/proofage-media-'.uniqid().'.jpg';
        $client = $this->client(['*' => FakeHttpClient::raw('binary-image-bytes')], [], $fake);

        $client->makeStreamedRequest('GET', 'verifications/ver_1/media/med_1', $path);

        $this->assertSame('binary-image-bytes', file_get_contents($path));
        $this->assertSame($path, $fake->sent()[0]->sink);
        $this->assertFalse($fake->sent()[0]->stream);

        unlink($path);
    }

    public function test_streamed_request_maps_errors_the_same_way(): void
    {
        $client = $this->client(['*' => FakeHttpClient::json(['error' => ['code' => 'MEDIA_NOT_FOUND', 'message' => 'Media not found']], 404)]);

        try {
            $client->makeStreamedRequest('GET', 'verifications/ver_1/media/med_1');
            $this->fail('Expected a ProofAgeException.');
        } catch (ProofAgeException $e) {
            $this->assertSame(404, $e->getCode());
            $this->assertSame('MEDIA_NOT_FOUND', $e->getErrorCode());
        }
    }

    public function test_download_does_not_retry_a_rate_limit(): void
    {
        // Ported from proofage-laravel-client tests/VerificationResourceTest.php:281-297.
        $client = $this->client(['api.test.com/v1/verifications/ver_1/media/med_1' => [
            FakeHttpClient::json(['error' => ['code' => 'RATE_LIMIT']], 429),
            FakeHttpClient::raw('bytes'),
        ]], [], $fake);

        try {
            $client->makeStreamedRequest('GET', 'verifications/ver_1/media/med_1');
            $this->fail('Expected the 429 to surface instead of being retried.');
        } catch (ProofAgeException $exception) {
            $this->assertSame(429, $exception->getCode());
        }

        $fake->assertSentCount(1);
    }

    public function test_interactive_requests_still_retry_a_rate_limit(): void
    {
        // Ported from proofage-laravel-client tests/VerificationResourceTest.php:299-311.
        $client = $this->client(['api.test.com/v1/verifications/ver_1/document' => [
            FakeHttpClient::json(['error' => ['code' => 'RATE_LIMIT']], 429),
            FakeHttpClient::json(['document' => ['fields' => []], 'media' => [], 'meta' => []]),
        ]], ['retry_delay' => 0], $fake);

        $result = $client->makeRequest('GET', 'verifications/ver_1/document');

        $this->assertIsArray($result->json());
        $this->assertSame(2, $result->attempt());
        $fake->assertSentCount(2);
    }

    public function test_download_retry_attempts_can_be_raised_for_connection_failures(): void
    {
        // Ported from proofage-laravel-client tests/VerificationResourceTest.php:313-343.
        $client = $this->client(['*' => [
            FakeHttpClient::failedConnection('Connection timed out'),
            FakeHttpClient::raw('bytes'),
        ]], ['retry_delay' => 0, 'download_retry_attempts' => 2], $fake);

        $body = $client->makeStreamedRequest('GET', 'verifications/ver_1/media/med_1')->getBody();

        $this->assertSame('bytes', (string) $body);
        $fake->assertSentCount(2);
    }

    public function test_attempt_counts_are_clamped_to_at_least_one(): void
    {
        $client = $this->client(['*' => FakeHttpClient::json([])], ['retry_attempts' => 0, 'download_retry_attempts' => 0], $fake);

        $client->makeRequest('GET', 'workspace');
        $client->makeStreamedRequest('GET', 'verifications/ver_1/media/med_1');

        $this->assertSame(1, $fake->sent()[0]->retryPolicy->maxAttempts);
        $this->assertSame(1, $fake->sent()[1]->retryPolicy->maxAttempts);
    }

    public function test_timeout_and_retry_config_reach_the_request(): void
    {
        $client = $this->client(['*' => FakeHttpClient::json([])], ['timeout' => 7, 'retry_attempts' => 5, 'retry_delay' => 250], $fake);

        $client->makeRequest('GET', 'workspace');

        $sent = $fake->sent()[0];
        $this->assertSame(7, $sent->timeout);
        $this->assertSame(5, $sent->retryPolicy->maxAttempts);
        $this->assertSame(250, $sent->retryPolicy->delayMs);
    }

    /**
     * A host framework fakes time by replacing the sleep call (Laravel's Sleep::fake()),
     * which cannot reach a usleep() hard-wired inside the SDK: consumer test suites slept
     * for real, two seconds per retried test at the default delay.
     */
    public function test_an_injected_sleeper_receives_microseconds_and_nothing_sleeps_for_real(): void
    {
        $fake = new FakeHttpClient(['*' => [FakeHttpClient::json([], 429), FakeHttpClient::json([], 503), FakeHttpClient::json(['ok' => true])]]);
        $slept = [];
        $client = new Client(array_merge(self::CONFIG, ['retry_delay' => 1000]), $fake, null, static function (int $microseconds) use (&$slept): void {
            $slept[] = $microseconds;
        });

        $start = hrtime(true);
        $response = $client->makeRequest('GET', 'workspace');

        $this->assertSame(['ok' => true], $response->json());
        $this->assertSame(3, $response->attempt());
        $this->assertSame([1_000_000, 1_000_000], $slept, 'retry_delay is milliseconds; the sleeper gets microseconds, the unit of usleep() and Sleep::usleep().');
        $this->assertLessThan(500, (hrtime(true) - $start) / 1e6, 'With a sleeper injected, the client must not sleep for real.');
    }

    public function test_a_transport_failure_that_survives_every_attempt_is_a_transport_exception(): void
    {
        $client = $this->client(['*' => FakeHttpClient::failedConnection('Connection refused')], ['retry_delay' => 0], $fake);

        try {
            $client->makeRequest('GET', 'workspace');
            $this->fail('Expected a TransportException.');
        } catch (TransportException $e) {
            $this->assertSame('Connection refused', $e->getMessage());
            $this->assertNull($e->getResponse());
        }

        $fake->assertSentCount(3);
    }

    public function test_reused_client_does_not_duplicate_auth_headers_between_requests(): void
    {
        // Ported from proofage-laravel-client tests/VerificationResourceTest.php:453-476.
        $client = $this->client([
            'api.test.com/v1/workspace' => FakeHttpClient::json(['id' => 'ws_123']),
            'api.test.com/v1/verifications/ver_123/blocked-face' => FakeHttpClient::raw('', 204),
        ], [], $fake);

        $client->makeRequest('GET', 'workspace');
        $client->makeRequest('POST', 'verifications/ver_123/blocked-face');

        $blockedFace = $fake->sent()[1];
        $this->assertSame(1, count(array_filter(array_keys($blockedFace->headers), static fn (string $name): bool => strcasecmp($name, 'X-API-Key') === 0)));
        $this->assertSame('test-api-key', $blockedFace->header('X-API-Key'));
        $this->assertSame($this->signature('POST/v1/verifications/ver_123/blocked-face'), $blockedFace->header('X-HMAC-Signature'));
    }

    public function test_middleware_is_pushed_fluently_runs_before_signing_and_can_be_removed(): void
    {
        $client = $this->client(['*' => FakeHttpClient::json([])], [], $fake);

        $returned = $client->pushMiddleware(static fn (Request $request, callable $next): Response => $next($request->withHeader('X-Request-Id', 'req-1')), 'request-id');
        $this->assertSame($client, $returned);

        $client->makeRequest('GET', 'workspace');
        $this->assertSame('req-1', $fake->sent()[0]->header('X-Request-Id'));
        $this->assertSame('test-api-key', $fake->sent()[0]->header('X-API-Key'));

        $this->assertSame($client, $client->removeMiddleware('request-id'));
        $client->makeRequest('GET', 'workspace');
        $this->assertNull($fake->sent()[1]->header('X-Request-Id'));
    }

    public function test_event_listeners_are_registered_fluently_and_fire(): void
    {
        $client = $this->client(['api.test.com/v1/workspace' => FakeHttpClient::json(['id' => 'ws_1']), '*' => FakeHttpClient::failedConnection()], ['retry_attempts' => 1], $fake);
        $log = [];

        $this->assertSame($client, $client->onRequest(static function (RequestEvent $e) use (&$log): void {
            $log[] = ['request', $e->method(), $e->headers()['X-API-Key']];
        }));
        $this->assertSame($client, $client->onResponse(static function (ResponseEvent $e) use (&$log): void {
            $log[] = ['response', $e->status()];
        }));
        $this->assertSame($client, $client->onError(static function (ErrorEvent $e) use (&$log): void {
            $log[] = ['error', $e->exception()->getMessage()];
        }));

        $client->makeRequest('GET', 'workspace');

        try {
            $client->makeRequest('GET', 'consent');
        } catch (TransportException) {
        }

        $this->assertSame([
            ['request', 'GET', '****-key'],
            ['response', 200],
            ['request', 'GET', '****-key'],
            ['error', 'Connection refused'],
        ], $log);
    }

    public function test_client_is_not_final_so_it_can_be_subclassed_and_mocked(): void
    {
        $this->assertFalse((new \ReflectionClass(Client::class))->isFinal());
    }
}
