<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Tests\Http\Psr18;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ProofAge\Sdk\Client;
use ProofAge\Sdk\Exceptions\TransportException;
use ProofAge\Sdk\Http\Body\FilePart;
use ProofAge\Sdk\Http\Body\MultipartBody;
use ProofAge\Sdk\Http\Body\RawBody;
use ProofAge\Sdk\Http\HttpClient;
use ProofAge\Sdk\Http\Psr18\Psr18HttpClient;
use ProofAge\Sdk\Http\Request;
use ProofAge\Sdk\Http\Response;
use ProofAge\Sdk\Http\RetryPolicy;
use ProofAge\Sdk\Signing\Signer;
use ProofAge\Sdk\Tests\Support\EchoServer;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

#[Group('network-local')]
class Psr18HttpClientTest extends TestCase
{
    private static EchoServer $server;

    public static function setUpBeforeClass(): void
    {
        self::$server = EchoServer::start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
    }

    private function transport(?ClientInterface $client = null): Psr18HttpClient
    {
        $factory = new HttpFactory;

        return new Psr18HttpClient($client ?? new Guzzle(['timeout' => 5]), $factory, $factory);
    }

    /** @param array<string, string> $headers */
    private function request(string $method, string $path, RawBody|MultipartBody|null $body = null, array $headers = [], ?string $sink = null): Request
    {
        return new Request($method, self::$server->url($path), $path, $headers, $body, RetryPolicy::download(), 5, $sink);
    }

    /** @return array<string, mixed> */
    private function echo(Response $response): array
    {
        $decoded = $response->json();
        $this->assertIsArray($decoded, 'Echo server did not answer with JSON: '.$response->body());

        return $decoded;
    }

    public function test_it_is_an_http_client(): void
    {
        $this->assertInstanceOf(HttpClient::class, $this->transport());
    }

    public function test_get_sends_method_path_query_and_headers(): void
    {
        $request = $this->request('GET', '/v1/workspace?b=2&a=1', headers: ['X-API-Key' => 'key-1', 'Accept' => 'application/json']);

        $response = $this->transport()->send($request);
        $echo = $this->echo($response);

        $this->assertSame(200, $response->status());
        $this->assertSame('GET', $echo['method']);
        $this->assertSame('/v1/workspace', $echo['path']);
        $this->assertSame('b=2&a=1', $echo['query']);
        $this->assertSame('key-1', $echo['headers']['x-api-key']);
        $this->assertSame(['a=1', 'b=2'], $response->headers()['Set-Cookie']);
        $this->assertSame($request, $response->request);
    }

    public function test_raw_body_bytes_arrive_unchanged_and_sign_against_what_was_received(): void
    {
        $bytes = '{"callback_url":"https:\/\/example.com\/hook","name":"Jürgen"}';
        $signature = (new Signer('psr-secret'))->signRaw('POST', '/v1/verifications', $bytes);

        $echo = $this->echo($this->transport()->send($this->request('POST', '/v1/verifications', new RawBody($bytes), ['X-HMAC-Signature' => $signature])));

        $this->assertSame($bytes, base64_decode($echo['body']));
        $this->assertSame('application/json', $echo['headers']['content-type']);
        $this->assertSame(hash_hmac('sha256', 'POST'.$echo['path'].base64_decode($echo['body']), 'psr-secret'), $echo['headers']['x-hmac-signature']);
    }

    public function test_multipart_fields_and_files_arrive_and_hash_as_signed(): void
    {
        $part = new FilePart('file', 'front.jpg', random_bytes(1024));
        $fields = ['type' => 'document', 'side' => 'front'];
        $body = new MultipartBody($fields, [$part]);
        $signature = (new Signer('psr-secret'))->signMultipart('POST', '/v1/verifications/ver_1/media', $fields, $body->fileHashes());

        $echo = $this->echo($this->transport()->send($this->request('POST', '/v1/verifications/ver_1/media', $body, ['X-HMAC-Signature' => $signature])));

        $this->assertStringStartsWith('multipart/form-data; boundary=', $echo['headers']['content-type']);
        $this->assertSame($fields, $echo['fields']);
        $this->assertSame($part->sha256(), $echo['files'][0]['sha256']);
        $this->assertSame(
            (new Signer('psr-secret'))->signMultipart('POST', $echo['path'], $echo['fields'], array_column($echo['files'], 'sha256')),
            $echo['headers']['x-hmac-signature'],
        );
    }

    public function test_bodyless_post_sends_an_empty_body(): void
    {
        $echo = $this->echo($this->transport()->send($this->request('POST', '/v1/verifications/ver_1/submit')));

        $this->assertSame('POST', $echo['method']);
        $this->assertSame(0, $echo['body_length']);
    }

    public function test_http_error_statuses_and_redirects_are_responses(): void
    {
        foreach ([401, 422, 429, 500] as $status) {
            $response = $this->transport()->send($this->request('GET', "/status/{$status}"));

            $this->assertSame($status, $response->status());
            $this->assertSame('STATUS_'.$status, $response->json()['error']['code']);
        }

        $this->assertSame(302, $this->transport()->send($this->request('GET', '/status/302'))->status());
    }

    public function test_connection_failure_becomes_a_transport_exception_with_the_psr_exception_as_previous(): void
    {
        $port = EchoServer::freePort();
        $request = new Request('GET', "http://127.0.0.1:{$port}/v1/workspace", '/v1/workspace', [], null, RetryPolicy::download(), 5);

        try {
            $this->transport()->send($request);
            $this->fail('Expected a TransportException.');
        } catch (TransportException $e) {
            $this->assertInstanceOf(ClientExceptionInterface::class, $e->getPrevious());
            $this->assertSame(0, $e->getCode());
        }
    }

    public function test_any_client_exception_interface_is_wrapped(): void
    {
        $client = new class implements ClientInterface
        {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new class('boom') extends \RuntimeException implements ClientExceptionInterface {};
            }
        };

        try {
            $this->transport($client)->send($this->request('GET', '/v1/x'));
            $this->fail('Expected a TransportException.');
        } catch (TransportException $e) {
            $this->assertSame('boom', $e->getMessage());
            $this->assertInstanceOf(ClientExceptionInterface::class, $e->getPrevious());
        }
    }

    public function test_the_body_is_the_psr7_stream(): void
    {
        $response = $this->transport()->send($this->request('GET', '/bytes/1000'));

        $this->assertSame('image/jpeg', $response->header('content-type'));
        $this->assertSame(substr(str_repeat('0123456789abcdef', 63), 0, 1000), (string) $response->getBody());
    }

    public function test_a_sink_receives_the_body_and_the_response_streams_read_only_from_it(): void
    {
        $path = sys_get_temp_dir().'/proofage-psr18-sink-'.uniqid().'.jpg';

        $response = $this->transport()->send($this->request('GET', '/bytes/100', sink: $path));

        $this->assertSame(substr(str_repeat('0123456789abcdef', 7), 0, 100), file_get_contents($path));
        $this->assertSame($path, $response->getBody()->getMetadata('uri'));
        $this->assertFalse($response->getBody()->isWritable());

        $response->getBody()->close();
        unlink($path);
    }

    public function test_client_works_end_to_end_over_the_psr18_transport(): void
    {
        $client = new Client([
            'api_key' => 'psr-key',
            'secret_key' => 'psr-secret',
            'base_url' => self::$server->url(),
            'retry_attempts' => 1,
        ], $this->transport());

        $echo = $client->makeRequest('POST', 'verifications', ['callback_url' => 'https://example.com/hook'])->json();

        $this->assertSame('psr-key', $echo['headers']['x-api-key']);
        $this->assertSame(
            hash_hmac('sha256', 'POST'.$echo['path'].base64_decode($echo['body']), 'psr-secret'),
            $echo['headers']['x-hmac-signature'],
        );
    }
}
