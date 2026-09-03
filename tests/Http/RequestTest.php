<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProofAge\Sdk\Http\Body\RawBody;
use ProofAge\Sdk\Http\Request;
use ProofAge\Sdk\Http\RetryPolicy;

class RequestTest extends TestCase
{
    private function request(): Request
    {
        return new Request(
            method: 'post',
            url: 'https://api.test.com/v1/verifications',
            path: '/v1/verifications',
            headers: ['Accept' => 'application/json'],
            body: new RawBody('{"a":1}'),
            retryPolicy: RetryPolicy::interactive(3, 1000),
            timeout: 30,
        );
    }

    public function test_method_is_upper_cased(): void
    {
        $this->assertSame('POST', $this->request()->method);
    }

    public function test_defaults(): void
    {
        $request = $this->request();

        $this->assertNull($request->sink);
        $this->assertFalse($request->stream);
        $this->assertSame(1, $request->attempt);
    }

    public function test_header_lookup_is_case_insensitive(): void
    {
        $request = $this->request();

        $this->assertSame('application/json', $request->header('accept'));
        $this->assertSame('application/json', $request->header('ACCEPT'));
        $this->assertNull($request->header('X-Missing'));
    }

    public function test_with_header_replaces_case_insensitively_and_leaves_the_original_untouched(): void
    {
        $original = $this->request();

        $changed = $original->withHeader('accept', '*/*')->withHeader('X-Request-Id', 'abc');

        $this->assertSame(['accept' => '*/*', 'X-Request-Id' => 'abc'], $changed->headers);
        $this->assertSame(['Accept' => 'application/json'], $original->headers);
    }

    public function test_without_header_is_case_insensitive(): void
    {
        $changed = $this->request()->withoutHeader('ACCEPT');

        $this->assertSame([], $changed->headers);
    }

    public function test_with_body_url_path_and_attempt_keep_every_other_field(): void
    {
        $original = $this->request();
        $body = new RawBody('{"b":2}');

        $changed = $original
            ->withBody($body)
            ->withUrl('https://proxy.test/v1/verifications')
            ->withPath('/v1/verifications?x=1')
            ->withAttempt(3);

        $this->assertSame($body, $changed->body);
        $this->assertSame('https://proxy.test/v1/verifications', $changed->url);
        $this->assertSame('/v1/verifications?x=1', $changed->path);
        $this->assertSame(3, $changed->attempt);

        $this->assertSame('POST', $changed->method);
        $this->assertSame($original->headers, $changed->headers);
        $this->assertSame($original->retryPolicy, $changed->retryPolicy);
        $this->assertSame(30, $changed->timeout);

        $this->assertSame(1, $original->attempt);
        $this->assertSame('/v1/verifications', $original->path);
    }

    public function test_with_body_accepts_null(): void
    {
        $this->assertNull($this->request()->withBody(null)->body);
    }

    /** @return iterable<string, array{string}> */
    public static function valuesThatWouldInjectAHeader(): iterable
    {
        // A transport writes "Name: value" lines; a line break in the value ends the line
        // and whatever follows is sent as another header.
        yield 'crlf' => ["req-1\r\nX-Injected: 1"];
        yield 'lf' => ["req-1\nX-Injected: 1"];
        yield 'cr' => ["req-1\rX-Injected: 1"];
        yield 'nul' => ["req-1\0"];
    }

    #[DataProvider('valuesThatWouldInjectAHeader')]
    public function test_with_header_rejects_a_value_containing_a_line_break_or_nul(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('X-Request-Id');

        $this->request()->withHeader('X-Request-Id', $value);
    }

    #[DataProvider('valuesThatWouldInjectAHeader')]
    public function test_the_constructor_rejects_the_same_values(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Request('GET', 'https://api.test.com/v1/x', '/v1/x', ['X-Request-Id' => $value], null, RetryPolicy::interactive(), 30);
    }

    /** @return iterable<string, array{string}> */
    public static function namesThatAreNotHeaderTokens(): iterable
    {
        yield 'line break' => ["X-A\r\nX-B"];
        yield 'space' => ['X A'];
        yield 'colon' => ['X:A'];
        yield 'empty' => [''];
    }

    #[DataProvider('namesThatAreNotHeaderTokens')]
    public function test_with_header_rejects_a_name_that_is_not_a_token(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->request()->withHeader($name, 'v');
    }

    public function test_ordinary_header_values_are_accepted(): void
    {
        $request = $this->request()
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('X-Note', "tabs\tand spaces, quotes \"x\" and unicode Jürgen")
            ->withHeader('X-Empty', '');

        $this->assertSame('application/json; charset=utf-8', $request->header('content-type'));
        $this->assertSame('', $request->header('X-Empty'));
    }
}
