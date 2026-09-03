<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Tests\Exceptions;

use PHPUnit\Framework\TestCase;
use ProofAge\Sdk\Exceptions\ExceptionInterface;
use ProofAge\Sdk\Exceptions\ProofAgeException;
use ProofAge\Sdk\Exceptions\TransportException;
use ProofAge\Sdk\Http\Request;
use ProofAge\Sdk\Http\Response;
use ProofAge\Sdk\Http\RetryPolicy;
use ProofAge\Sdk\Stream\ResourceStream;

class ExceptionsTest extends TestCase
{
    private function response(int $status, string $body): Response
    {
        $request = new Request('GET', 'https://api.test.com/v1/workspace', '/v1/workspace', [], null, RetryPolicy::interactive(), 30);

        return new Response($status, ['Content-Type' => 'application/json'], ResourceStream::fromString($body), $request);
    }

    public function test_every_sdk_exception_is_catchable_through_the_marker_interface(): void
    {
        $this->assertInstanceOf(ExceptionInterface::class, new ProofAgeException('x'));
        $this->assertInstanceOf(ExceptionInterface::class, new TransportException('x'));
        $this->assertInstanceOf(\Throwable::class, new ProofAgeException('x'));
    }

    public function test_from_response_takes_the_message_code_and_error_data_from_the_body(): void
    {
        $response = $this->response(404, '{"error":{"code":"MEDIA_NOT_FOUND","message":"Media not found"}}');

        $exception = ProofAgeException::fromResponse($response);

        $this->assertSame('Media not found', $exception->getMessage());
        $this->assertSame(404, $exception->getCode());
        $this->assertSame($response, $exception->getResponse());
        $this->assertSame(['code' => 'MEDIA_NOT_FOUND', 'message' => 'Media not found'], $exception->getErrorData());
        $this->assertSame('MEDIA_NOT_FOUND', $exception->getErrorCode());
    }

    public function test_from_response_falls_back_to_a_generic_message(): void
    {
        $exception = ProofAgeException::fromResponse($this->response(500, 'not json'));

        $this->assertSame('ProofAge API request failed', $exception->getMessage());
        $this->assertSame(500, $exception->getCode());
        $this->assertSame([], $exception->getErrorData());
        $this->assertNull($exception->getErrorCode());
    }

    public function test_from_response_accepts_an_explicit_message_that_the_body_can_still_override(): void
    {
        $this->assertSame('Custom', ProofAgeException::fromResponse($this->response(500, '{}'), 'Custom')->getMessage());
        $this->assertSame('From body', ProofAgeException::fromResponse($this->response(500, '{"error":{"message":"From body"}}'), 'Custom')->getMessage());
    }

    public function test_a_plain_exception_carries_no_response(): void
    {
        $previous = new \RuntimeException('cause');
        $exception = new ProofAgeException('API key is required', 0, $previous);

        $this->assertNull($exception->getResponse());
        $this->assertSame($previous, $exception->getPrevious());
        $this->assertSame([], $exception->getErrorData());
    }

    public function test_transport_exception_carries_the_transport_code_and_previous_but_never_a_response(): void
    {
        $previous = new \RuntimeException('curl');
        $exception = new TransportException('Connection refused', 7, $previous);

        $this->assertInstanceOf(ProofAgeException::class, $exception);
        $this->assertSame(7, $exception->getCode());
        $this->assertSame($previous, $exception->getPrevious());
        $this->assertNull($exception->getResponse());
    }

    public function test_a_transport_exception_is_retryable_unless_the_transport_says_otherwise(): void
    {
        $this->assertTrue((new TransportException('Connection refused', 7))->isRetryable());
        $this->assertTrue((new TransportException('timeout'))->isRetryable());
        $this->assertFalse((new TransportException('URL rejected', 3, null, false))->isRetryable());
    }
}
