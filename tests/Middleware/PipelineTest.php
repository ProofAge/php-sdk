<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Tests\Middleware;

use PHPUnit\Framework\TestCase;
use ProofAge\Sdk\Events\RequestEvent;
use ProofAge\Sdk\Http\Request;
use ProofAge\Sdk\Http\Response;
use ProofAge\Sdk\Http\RetryPolicy;
use ProofAge\Sdk\Middleware\Pipeline;
use ProofAge\Sdk\Middleware\RetryMiddleware;
use ProofAge\Sdk\Middleware\SignMiddleware;
use ProofAge\Sdk\Signing\Signer;
use ProofAge\Sdk\Testing\FakeHttpClient;

class PipelineTest extends TestCase
{
    private FakeHttpClient $fake;

    private SignMiddleware $sign;

    private Pipeline $pipeline;

    /** @var list<string> */
    private array $trace = [];

    protected function setUp(): void
    {
        $this->fake = new FakeHttpClient(['*' => [FakeHttpClient::json([], 429), FakeHttpClient::json(['ok' => true])]]);
        $this->sign = new SignMiddleware('key', new Signer('secret'));
        $this->pipeline = new Pipeline($this->fake, $this->sign, new RetryMiddleware(static fn (int $ms) => null));
    }

    private function request(): Request
    {
        return new Request('GET', 'https://api.test.com/v1/workspace', '/v1/workspace', [], null, RetryPolicy::interactive(3, 0), 30);
    }

    private function tracing(string $name): callable
    {
        return function (Request $request, callable $next) use ($name): Response {
            $this->trace[] = "{$name}:in:{$request->attempt}";
            $response = $next($request);
            $this->trace[] = "{$name}:out:{$response->status()}";

            return $response;
        };
    }

    public function test_layer_order_is_retry_then_user_middleware_in_push_order_then_sign(): void
    {
        $this->pipeline->push($this->tracing('A'), 'a');
        $this->pipeline->push($this->tracing('B'), 'b');
        $this->sign->onRequest(function (RequestEvent $event): void {
            $this->trace[] = "sign:{$event->attempt()}";
        });

        $response = $this->pipeline->send($this->request());

        $this->assertSame(200, $response->status());
        $this->assertSame(2, $response->attempt());
        $this->assertSame([
            'A:in:1', 'B:in:1', 'sign:1', 'B:out:429', 'A:out:429',
            'A:in:2', 'B:in:2', 'sign:2', 'B:out:200', 'A:out:200',
        ], $this->trace, 'Retry is outermost, so user middleware runs once per attempt; Sign is innermost.');
    }

    public function test_remove_by_name(): void
    {
        $this->pipeline->push($this->tracing('A'), 'a');
        $this->pipeline->push($this->tracing('B'), 'b');
        $this->pipeline->remove('a');

        $this->pipeline->send($this->request());

        $this->assertSame(['B:in:1', 'B:out:429', 'B:in:2', 'B:out:200'], $this->trace);
    }

    public function test_removing_an_unknown_name_is_a_no_op(): void
    {
        $this->pipeline->push($this->tracing('A'), 'a');
        $this->pipeline->remove('nope');

        $this->pipeline->send($this->request());

        $this->assertSame(['A:in:1', 'A:out:429', 'A:in:2', 'A:out:200'], $this->trace);
    }

    public function test_the_once_per_call_idiom_uses_attempt_one(): void
    {
        $calls = 0;
        $this->pipeline->push(static function (Request $request, callable $next) use (&$calls): Response {
            if ($request->attempt === 1) {
                $calls++;
            }

            return $next($request);
        });

        $this->pipeline->send($this->request());

        $this->assertSame(1, $calls);
        $this->fake->assertSentCount(2);
    }

    public function test_a_short_circuiting_middleware_sends_nothing_and_fires_no_event(): void
    {
        $events = 0;
        $this->sign->onRequest(static function () use (&$events): void {
            $events++;
        });
        $this->pipeline->push(static fn (Request $request, callable $next): Response => FakeHttpClient::json(['cached' => true])->withRequest($request));

        $response = $this->pipeline->send($this->request());

        $this->assertSame(['cached' => true], $response->json());
        $this->fake->assertNothingSent();
        $this->assertSame(0, $events);
    }
}
