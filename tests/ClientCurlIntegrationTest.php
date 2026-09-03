<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ProofAge\Sdk\Client;
use ProofAge\Sdk\Exceptions\AuthenticationException;
use ProofAge\Sdk\Exceptions\ProofAgeException;
use ProofAge\Sdk\Exceptions\TransportException;
use ProofAge\Sdk\Exceptions\ValidationException;
use ProofAge\Sdk\Http\Curl\CurlHttpClient;
use ProofAge\Sdk\Signing\Signer;
use ProofAge\Sdk\Tests\Support\EchoServer;

/**
 * Client over the real cURL transport against PHP's built-in server: the signature the
 * client sent must verify against the bytes the server received (section 8.2).
 */
#[Group('network-local')]
class ClientCurlIntegrationTest extends TestCase
{
    private const SECRET = 'integration-secret';

    private static EchoServer $server;

    public static function setUpBeforeClass(): void
    {
        self::$server = EchoServer::start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
    }

    /** @param array<string, mixed> $overrides */
    private function client(array $overrides = []): Client
    {
        return new Client(array_merge([
            'api_key' => 'integration-key',
            'secret_key' => self::SECRET,
            'base_url' => self::$server->url(),
            'retry_attempts' => 1,
            'retry_delay' => 0,
        ], $overrides));
    }

    public function test_it_uses_the_curl_transport_by_default(): void
    {
        $this->assertInstanceOf(CurlHttpClient::class, $this->client()->transport());
    }

    public function test_json_request_signature_verifies_against_the_received_bytes(): void
    {
        $echo = $this->client()->makeRequest('POST', 'verifications', [
            'callback_url' => 'https://example.com/hook',
            'external_metadata' => ['name' => 'Jürgen', 'note' => 'Said "no"'],
        ])->json();

        $this->assertSame('POST', $echo['method']);
        $this->assertSame('/v1/verifications', $echo['path']);
        $this->assertSame('integration-key', $echo['headers']['x-api-key']);
        $this->assertSame('application/json', $echo['headers']['content-type']);
        $this->assertSame('application/json', $echo['headers']['accept']);

        $received = base64_decode($echo['body']);
        $this->assertSame(
            hash_hmac('sha256', 'POST'.$echo['path'].$received, self::SECRET),
            $echo['headers']['x-hmac-signature'],
        );
    }

    public function test_multipart_request_signature_verifies_against_the_received_fields_and_file_hashes(): void
    {
        $path = sys_get_temp_dir().'/proofage-integration-'.uniqid().'.jpg';
        file_put_contents($path, random_bytes(4096));

        try {
            $echo = $this->client()->makeRequest('POST', 'verifications/ver_1/media', [
                'type' => 'document',
                'side' => 'front',
                'document' => 'passport',
                'device_info' => '{"os":"iOS 17.4","ua":"Mozilla/5.0 (iPhone)"}',
            ], ['file' => $path])->json();
        } finally {
            unlink($path);
        }

        $this->assertStringStartsWith('multipart/form-data; boundary=', $echo['headers']['content-type']);
        $this->assertSame(['type' => 'document', 'side' => 'front', 'document' => 'passport', 'device_info' => '{"os":"iOS 17.4","ua":"Mozilla/5.0 (iPhone)"}'], $echo['fields']);
        $this->assertSame('file', $echo['files'][0]['field']);
        $this->assertSame(4096, $echo['files'][0]['size']);

        $this->assertSame(
            (new Signer(self::SECRET))->signMultipart('POST', $echo['path'], $echo['fields'], array_column($echo['files'], 'sha256')),
            $echo['headers']['x-hmac-signature'],
        );
    }

    public function test_a_query_string_reaches_the_server_normalized_and_is_signed_that_way(): void
    {
        $echo = $this->client()->makeRequest('GET', 'verifications?status=approved&limit=10&q=a+b')->json();

        $this->assertSame('limit=10&q=a%20b&status=approved', $echo['query']);
        $this->assertSame(
            hash_hmac('sha256', 'GET'.$echo['path'].'?'.$echo['query'], self::SECRET),
            $echo['headers']['x-hmac-signature'],
        );
    }

    public function test_a_non_ascii_segment_reaches_the_server_percent_encoded_and_is_signed_that_way(): void
    {
        $echo = $this->client()->makeRequest('GET', 'verifications/Jürgen/document')->json();

        $this->assertSame('/v1/verifications/J%C3%BCrgen/document', $echo['path'], 'The raw request-target path, which is what the API signs via getPathInfo().');
        $this->assertSame(hash_hmac('sha256', 'GET'.$echo['path'], self::SECRET), $echo['headers']['x-hmac-signature']);
    }

    public function test_a_malformed_url_fails_once_without_retrying(): void
    {
        $attempts = 0;
        $slept = [];
        $client = new Client([
            'api_key' => 'integration-key',
            'secret_key' => self::SECRET,
            'base_url' => 'http://[::1',
            'retry_attempts' => 3,
            'retry_delay' => 1000,
        ], null, null, static function (int $microseconds) use (&$slept): void {
            $slept[] = $microseconds;
        });
        $client->onRequest(static function () use (&$attempts): void {
            $attempts++;
        });

        try {
            $client->makeRequest('GET', 'workspace');
            $this->fail('Expected a TransportException.');
        } catch (TransportException $e) {
            $this->assertSame(CURLE_URL_MALFORMAT, $e->getCode());
            $this->assertFalse($e->isRetryable());
        }

        $this->assertSame(1, $attempts, 'A malformed URL is deterministic and local; retrying it costs three attempts and two seconds for nothing.');
        $this->assertSame([], $slept);
    }

    public function test_bodyless_post_is_signed_over_method_and_path(): void
    {
        $echo = $this->client()->makeRequest('POST', 'verifications/ver_1/submit')->json();

        $this->assertSame('POST', $echo['method']);
        $this->assertSame(0, $echo['body_length']);
        $this->assertSame(hash_hmac('sha256', 'POST/v1/verifications/ver_1/submit', self::SECRET), $echo['headers']['x-hmac-signature']);
    }

    public function test_error_statuses_map_to_exceptions_through_the_real_transport(): void
    {
        $client = $this->client();

        try {
            $client->makeRequest('GET', 'status/401');
            $this->fail('Expected an AuthenticationException.');
        } catch (AuthenticationException $e) {
            $this->assertSame('Status 401', $e->getMessage());
        }

        try {
            $client->makeRequest('GET', 'status/422');
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame(['field' => ['The field is required.']], $e->getErrors());
        }

        try {
            $client->makeRequest('GET', 'status/500');
            $this->fail('Expected a ProofAgeException.');
        } catch (ProofAgeException $e) {
            $this->assertSame(500, $e->getCode());
            $this->assertSame('STATUS_500', $e->getErrorCode());
        }
    }

    public function test_a_timeout_surfaces_as_a_transport_exception(): void
    {
        $this->expectException(TransportException::class);

        $this->client(['timeout' => 1])->makeRequest('GET', 'slow');
    }

    public function test_streamed_download_and_sink(): void
    {
        $client = $this->client();
        $expected = substr(str_repeat('0123456789abcdef', 7), 0, 100);

        $stream = $client->makeStreamedRequest('GET', 'bytes/100')->getBody();
        $this->assertSame($expected, $stream->getContents());

        $path = sys_get_temp_dir().'/proofage-integration-sink-'.uniqid().'.jpg';
        $client->makeStreamedRequest('GET', 'bytes/100', $path);
        $this->assertSame($expected, file_get_contents($path));
        unlink($path);
    }
}
