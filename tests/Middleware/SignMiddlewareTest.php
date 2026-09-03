<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Tests\Middleware;

use PHPUnit\Framework\TestCase;
use ProofAge\Sdk\Events\ErrorEvent;
use ProofAge\Sdk\Events\RequestEvent;
use ProofAge\Sdk\Events\ResponseEvent;
use ProofAge\Sdk\Exceptions\TransportException;
use ProofAge\Sdk\Http\Body\FilePart;
use ProofAge\Sdk\Http\Body\MultipartBody;
use ProofAge\Sdk\Http\Body\RawBody;
use ProofAge\Sdk\Http\Request;
use ProofAge\Sdk\Http\Response;
use ProofAge\Sdk\Http\RetryPolicy;
use ProofAge\Sdk\Middleware\Pipeline;
use ProofAge\Sdk\Middleware\RetryMiddleware;
use ProofAge\Sdk\Middleware\SignMiddleware;
use ProofAge\Sdk\Signing\Signer;
use ProofAge\Sdk\Testing\FakeHttpClient;

/**
 * The invariant of section 8.2: the bytes we sign are the bytes we send, and middleware
 * mutates the request before signing while events see the already-signed request.
 */
class SignMiddlewareTest extends TestCase
{
    private const SECRET = 'test-secret-key';

    private const API_KEY = 'test-api-key-7f2a';

    private FakeHttpClient $fake;

    private SignMiddleware $sign;

    private Pipeline $pipeline;

    protected function setUp(): void
    {
        $this->fake = new FakeHttpClient(['*' => FakeHttpClient::json(['ok' => true], 200, ['Content-Length' => '11'])]);
        $this->sign = new SignMiddleware(self::API_KEY, new Signer(self::SECRET));
        $this->pipeline = new Pipeline($this->fake, $this->sign, new RetryMiddleware(static fn (int $ms) => null));
    }

    private function request(RawBody|MultipartBody|null $body = null, string $method = 'POST', string $path = '/v1/verifications'): Request
    {
        return new Request($method, 'https://api.test.com'.$path, $path, ['Accept' => 'application/json'], $body, RetryPolicy::interactive(3, 0), 30);
    }

    public function test_the_signature_covers_the_bytes_the_transport_received(): void
    {
        $this->pipeline->send($this->request(new RawBody('{"callback_url":"https:\/\/example.com"}')));

        $sent = $this->fake->sent()[0];
        $this->assertInstanceOf(RawBody::class, $sent->body);
        $this->assertSame(self::API_KEY, $sent->header('X-API-Key'));
        $this->assertSame(
            hash_hmac('sha256', 'POST/v1/verifications'.$sent->body->bytes, self::SECRET),
            $sent->header('X-HMAC-Signature'),
        );
    }

    public function test_a_middleware_that_replaces_the_body_is_signed_over_the_replacement(): void
    {
        $this->pipeline->push(static fn (Request $request, callable $next): Response => $next($request->withBody(new RawBody('{"replaced":true}'))));

        $this->pipeline->send($this->request(new RawBody('{"original":true}')));

        $sent = $this->fake->sent()[0];
        $this->assertInstanceOf(RawBody::class, $sent->body);
        $this->assertSame('{"replaced":true}', $sent->body->bytes);
        $this->assertSame(hash_hmac('sha256', 'POST/v1/verifications{"replaced":true}', self::SECRET), $sent->header('X-HMAC-Signature'));
    }

    public function test_a_middleware_that_adds_a_header_does_not_change_the_signature(): void
    {
        $this->pipeline->send($this->request(new RawBody('{"a":1}')));
        $plain = $this->fake->sent()[0]->header('X-HMAC-Signature');

        $this->pipeline->push(static fn (Request $request, callable $next): Response => $next($request->withHeader('X-Request-Id', 'req-1')));
        $this->pipeline->send($this->request(new RawBody('{"a":1}')));

        $sent = $this->fake->sent()[1];
        $this->assertSame('req-1', $sent->header('X-Request-Id'));
        $this->assertSame($plain, $sent->header('X-HMAC-Signature'));
    }

    public function test_a_middleware_that_changes_the_path_is_signed_over_the_new_path(): void
    {
        $this->pipeline->push(static fn (Request $request, callable $next): Response => $next($request->withPath('/v1/verifications?limit=10')));

        $this->pipeline->send($this->request(null, 'GET'));

        $this->assertSame(hash_hmac('sha256', 'GET/v1/verifications?limit=10', self::SECRET), $this->fake->sent()[0]->header('X-HMAC-Signature'));
    }

    public function test_middleware_never_sees_the_auth_headers_but_the_request_event_does(): void
    {
        $seenByMiddleware = [];
        $this->pipeline->push(static function (Request $request, callable $next) use (&$seenByMiddleware): Response {
            $seenByMiddleware['first'] = [$request->header('X-API-Key'), $request->header('X-HMAC-Signature')];

            return $next($request);
        });
        $this->pipeline->push(static function (Request $request, callable $next) use (&$seenByMiddleware): Response {
            $seenByMiddleware['last'] = [$request->header('X-API-Key'), $request->header('X-HMAC-Signature')];

            return $next($request);
        });

        $event = null;
        $this->sign->onRequest(static function (RequestEvent $e) use (&$event): void {
            $event = $e;
        });

        $this->pipeline->send($this->request(new RawBody('{"a":1}')));

        $this->assertSame(['first' => [null, null], 'last' => [null, null]], $seenByMiddleware);
        $this->assertInstanceOf(RequestEvent::class, $event);
        $this->assertSame(hash_hmac('sha256', 'POST/v1/verifications{"a":1}', self::SECRET), $event->raw()->header('X-HMAC-Signature'));
        $this->assertSame(self::API_KEY, $event->raw()->header('X-API-Key'));
    }

    public function test_multipart_is_signed_over_fields_and_file_hashes(): void
    {
        $part = new FilePart('file', 'front.jpg', 'not-really-a-jpeg');
        $fields = ['type' => 'document', 'side' => 'front'];

        $this->pipeline->send($this->request(new MultipartBody($fields, [$part]), 'POST', '/v1/verifications/ver_1/media'));

        $this->assertSame(
            (new Signer(self::SECRET))->signMultipart('POST', '/v1/verifications/ver_1/media', $fields, [$part->sha256()]),
            $this->fake->sent()[0]->header('X-HMAC-Signature'),
        );
    }

    public function test_the_request_event_is_a_redacted_view_of_the_signed_request(): void
    {
        $event = null;
        $this->sign->onRequest(static function (RequestEvent $e) use (&$event): void {
            $event = $e;
        });

        $this->pipeline->send($this->request(new RawBody('{"a":1}')));

        $this->assertInstanceOf(RequestEvent::class, $event);
        $this->assertSame('POST', $event->method());
        $this->assertSame('https://api.test.com/v1/verifications', $event->url());
        $this->assertSame('/v1/verifications', $event->path());
        $this->assertSame(1, $event->attempt());

        $headers = $event->headers();
        $this->assertSame('application/json', $headers['Accept']);
        $this->assertSame('****7f2a', $headers['X-API-Key']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}\.\.\.$/', $headers['X-HMAC-Signature']);
        $this->assertSame(['kind' => 'json', 'bytes' => 7, 'sha256' => hash('sha256', '{"a":1}')], $event->body());
    }

    public function test_the_response_event_carries_status_headers_timing_attempt_and_size_but_no_body(): void
    {
        $event = null;
        $this->sign->onResponse(static function (ResponseEvent $e) use (&$event): void {
            $event = $e;
        });

        $response = $this->pipeline->send($this->request(new RawBody('{"a":1}')));

        $this->assertInstanceOf(ResponseEvent::class, $event);
        $this->assertSame(200, $event->status());
        $this->assertSame(['application/json'], $event->headers()['Content-Type']);
        $this->assertSame('application/json', $event->contentType());
        $this->assertSame(11, $event->contentLength());
        $this->assertSame(1, $event->attempt());
        $this->assertGreaterThan(0.0, $event->durationMs());
        $this->assertSame($event->durationMs(), $response->durationMs, 'One clock, one place: the event and the returned response share the measurement.');
        $this->assertSame('POST', $event->request()->method());
        $this->assertSame($response, $event->raw());
        $this->assertFalse(method_exists($event, 'body'), 'ResponseEvent must not expose the body.');
    }

    public function test_content_length_falls_back_to_the_buffered_size_unless_the_response_is_streamed(): void
    {
        $fake = new FakeHttpClient(['*' => FakeHttpClient::raw('twelve bytes')]);
        $pipeline = new Pipeline($fake, $this->sign, new RetryMiddleware(static fn (int $ms) => null));
        $events = [];
        $this->sign->onResponse(static function (ResponseEvent $e) use (&$events): void {
            $events[] = $e;
        });

        $pipeline->send($this->request(null, 'GET'));
        $pipeline->send(new Request('GET', 'https://api.test.com/v1/m', '/v1/m', [], null, RetryPolicy::download(), 30, stream: true));

        $this->assertSame(12, $events[0]->contentLength());
        $this->assertNull($events[1]->contentLength(), 'A streamed body is never touched by an event.');
    }

    public function test_an_http_error_status_is_a_response_event_not_an_error_event(): void
    {
        $fake = new FakeHttpClient(['*' => FakeHttpClient::json(['error' => ['message' => 'nope']], 401)]);
        $pipeline = new Pipeline($fake, $this->sign, new RetryMiddleware(static fn (int $ms) => null));
        $statuses = [];
        $errors = 0;
        $this->sign->onResponse(static function (ResponseEvent $e) use (&$statuses): void {
            $statuses[] = $e->status();
        });
        $this->sign->onError(static function (ErrorEvent $e) use (&$errors): void {
            $errors++;
        });

        $response = $pipeline->send($this->request(null, 'GET'));

        $this->assertSame(401, $response->status());
        $this->assertSame([401], $statuses);
        $this->assertSame(0, $errors);
    }

    public function test_a_transport_failure_fires_the_error_event_then_propagates(): void
    {
        $fake = new FakeHttpClient(['*' => FakeHttpClient::failedConnection('Connection refused')]);
        $pipeline = new Pipeline($fake, $this->sign, new RetryMiddleware(static fn (int $ms) => null));
        $events = [];
        $this->sign->onError(static function (ErrorEvent $e) use (&$events): void {
            $events[] = $e;
        });

        try {
            $pipeline->send(new Request('GET', 'https://api.test.com/v1/m', '/v1/m', [], null, RetryPolicy::download(), 30));
            $this->fail('Expected a TransportException.');
        } catch (TransportException $e) {
            $this->assertSame('Connection refused', $e->getMessage());
        }

        $this->assertCount(1, $events);
        $this->assertSame($e, $events[0]->exception());
        $this->assertSame(1, $events[0]->attempt());
        $this->assertGreaterThan(0.0, $events[0]->durationMs());
        $this->assertSame('****7f2a', $events[0]->request()->headers()['X-API-Key']);
        $this->assertSame($fake->sent()[0], $events[0]->raw());
    }

    public function test_events_fire_once_per_attempt(): void
    {
        $fake = new FakeHttpClient(['*' => [FakeHttpClient::json([], 429), FakeHttpClient::json([])]]);
        $pipeline = new Pipeline($fake, $this->sign, new RetryMiddleware(static fn (int $ms) => null));
        $requests = [];
        $responses = [];
        $this->sign->onRequest(static function (RequestEvent $e) use (&$requests): void {
            $requests[] = $e->attempt();
        });
        $this->sign->onResponse(static function (ResponseEvent $e) use (&$responses): void {
            $responses[] = [$e->attempt(), $e->status()];
        });

        $pipeline->send($this->request(null, 'GET'));

        $this->assertSame([1, 2], $requests);
        $this->assertSame([[1, 429], [2, 200]], $responses);
    }

    public function test_a_listener_that_throws_aborts_the_request_unwrapped(): void
    {
        $this->sign->onRequest(static function (RequestEvent $e): void {
            throw new \DomainException('listener failed');
        });

        try {
            $this->pipeline->send($this->request(null, 'GET'));
            $this->fail('Expected the listener exception.');
        } catch (\DomainException $e) {
            $this->assertSame('listener failed', $e->getMessage());
        }

        $this->fake->assertNothingSent();
    }

    public function test_listeners_are_registered_fluently_and_called_in_order(): void
    {
        $order = [];
        $this->sign
            ->onRequest(static function () use (&$order): void {
                $order[] = 'r1';
            })
            ->onRequest(static function () use (&$order): void {
                $order[] = 'r2';
            })
            ->onResponse(static function () use (&$order): void {
                $order[] = 's1';
            });

        $this->pipeline->send($this->request(null, 'GET'));

        $this->assertSame(['r1', 'r2', 's1'], $order);
    }
}
