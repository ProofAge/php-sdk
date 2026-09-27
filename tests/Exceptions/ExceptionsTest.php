<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Tests\Exceptions;

use PHPUnit\Framework\TestCase;
use ProofAge\Sdk\Exceptions\DefaultExceptionFactory;
use ProofAge\Sdk\Exceptions\ExceptionInterface;
use ProofAge\Sdk\Exceptions\ProofAgeException;
use ProofAge\Sdk\Exceptions\TransportException;
use ProofAge\Sdk\Exceptions\ValidationException;
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

    public function test_a_flat_code_and_message_body_is_read_as_the_error(): void
    {
        $exception = ProofAgeException::fromResponse($this->response(402, '{"code":"PAYMENT_METHOD_REQUIRED","message":"Add a payment method","free_verifications_remaining":0,"trial_ends_at":null,"trial_active":false}'));

        $this->assertSame('Add a payment method', $exception->getMessage());
        $this->assertSame(402, $exception->getCode());
        $this->assertSame('PAYMENT_METHOD_REQUIRED', $exception->getErrorCode());
        $this->assertSame(0, $exception->getErrorData()['free_verifications_remaining']);
        $this->assertFalse($exception->getErrorData()['trial_active']);
    }

    public function test_a_media_quality_error_carries_its_code_through_the_validation_exception(): void
    {
        $exception = (new DefaultExceptionFactory)->fromResponse($this->response(422, '{"code":"FACE_NOT_FOUND","message":"Face validation failed."}'));

        $this->assertInstanceOf(ValidationException::class, $exception);
        $this->assertSame('FACE_NOT_FOUND', $exception->getErrorCode());
        $this->assertSame('Face validation failed.', $exception->getMessage());
        $this->assertSame([], $exception->getErrors());

        $unavailable = (new DefaultExceptionFactory)->fromResponse($this->response(500, '{"code":"VALIDATION_SERVICE_UNAVAILABLE","message":"Media validation is not available. Try again later."}'));
        $this->assertSame('VALIDATION_SERVICE_UNAVAILABLE', $unavailable->getErrorCode());
        $this->assertSame('Media validation is not available. Try again later.', $unavailable->getMessage());
    }

    public function test_a_laravel_validation_body_gives_the_message_and_the_errors_but_no_code(): void
    {
        $exception = (new DefaultExceptionFactory)->fromResponse($this->response(422, '{"message":"The type field is required.","errors":{"type":["The type field is required."]}}'));

        $this->assertInstanceOf(ValidationException::class, $exception);
        $this->assertSame('The type field is required.', $exception->getMessage());
        $this->assertNull($exception->getErrorCode());
        $this->assertSame(['type' => ['The type field is required.']], $exception->getErrors());
    }

    public function test_a_message_only_body_gives_the_message_and_no_code(): void
    {
        $forbidden = ProofAgeException::fromResponse($this->response(403, '{"message":"Access denied to this verification."}'));
        $this->assertSame('Access denied to this verification.', $forbidden->getMessage());
        $this->assertNull($forbidden->getErrorCode());
        $this->assertSame(['message' => 'Access denied to this verification.'], $forbidden->getErrorData());

        $missing = ProofAgeException::fromResponse($this->response(404, '{"message":"Resource not found"}'));
        $this->assertSame('Resource not found', $missing->getMessage());
        $this->assertSame(404, $missing->getCode());
    }

    public function test_a_json_list_or_an_empty_message_falls_back_to_the_generic_message(): void
    {
        $list = ProofAgeException::fromResponse($this->response(500, '[1,2]'));
        $this->assertSame('ProofAge API request failed', $list->getMessage());
        $this->assertSame([], $list->getErrorData());

        $this->assertSame('ProofAge API request failed', ProofAgeException::fromResponse($this->response(500, '{"message":""}'))->getMessage());
        $this->assertSame('ProofAge API request failed', ProofAgeException::fromResponse($this->response(500, '{"error":{"code":"X"},"message":"top"}'))->getMessage(), 'A nested error without a message does not borrow an unrelated top-level one.');
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
