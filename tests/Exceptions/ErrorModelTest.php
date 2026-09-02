<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Tests\Exceptions;

use PHPUnit\Framework\TestCase;
use ProofAge\Sdk\Exceptions\AuthenticationException;
use ProofAge\Sdk\Exceptions\DefaultExceptionFactory;
use ProofAge\Sdk\Exceptions\ExceptionFactory;
use ProofAge\Sdk\Exceptions\ExceptionInterface;
use ProofAge\Sdk\Exceptions\ProofAgeException;
use ProofAge\Sdk\Exceptions\ValidationException;
use ProofAge\Sdk\Exceptions\WebhookVerificationException;
use ProofAge\Sdk\Http\Response;
use ProofAge\Sdk\Testing\FakeHttpClient;

class ErrorModelTest extends TestCase
{
    public function test_authentication_and_validation_exceptions_extend_the_base(): void
    {
        $this->assertInstanceOf(ProofAgeException::class, new AuthenticationException('x'));
        $this->assertInstanceOf(ProofAgeException::class, new ValidationException('x'));
        $this->assertInstanceOf(ProofAgeException::class, new WebhookVerificationException('CODE', 'x'));
        $this->assertInstanceOf(ExceptionInterface::class, new WebhookVerificationException('CODE', 'x'));
    }

    public function test_from_response_returns_the_subclass_it_is_called_on(): void
    {
        $this->assertInstanceOf(AuthenticationException::class, AuthenticationException::fromResponse(FakeHttpClient::json(['error' => ['message' => 'Unauthorized']], 401)));
        $this->assertInstanceOf(ValidationException::class, ValidationException::fromResponse(FakeHttpClient::json(['error' => ['message' => 'Validation failed'], 'errors' => ['field' => ['required']]], 422)));
    }

    public function test_validation_exception_reads_the_top_level_errors_array(): void
    {
        $exception = ValidationException::fromResponse(FakeHttpClient::json([
            'error' => ['message' => 'Validation failed'],
            'errors' => ['callback_url' => ['The callback url field is required.']],
        ], 422));

        $this->assertSame('Validation failed', $exception->getMessage());
        $this->assertSame(422, $exception->getCode());
        $this->assertSame(['callback_url' => ['The callback url field is required.']], $exception->getErrors());
        $this->assertSame([], ValidationException::fromResponse(FakeHttpClient::json(['error' => ['message' => 'x']], 422))->getErrors());
        $this->assertSame([], (new ValidationException('no response'))->getErrors());
    }

    public function test_webhook_verification_exception_carries_code_status_and_the_rendered_body_shape(): void
    {
        $exception = new WebhookVerificationException('INVALID_SIGNATURE', 'HMAC signature is invalid');

        $this->assertSame('INVALID_SIGNATURE', $exception->errorCode);
        $this->assertSame(401, $exception->statusCode);
        $this->assertSame(401, $exception->getCode());
        $this->assertSame('HMAC signature is invalid', $exception->getMessage());
        $this->assertNull($exception->getResponse());
        $this->assertSame(['error' => ['code' => 'INVALID_SIGNATURE', 'message' => 'HMAC signature is invalid']], $exception->toArray());

        $teapot = new WebhookVerificationException('CONFIGURATION_ERROR', 'Middleware configuration is incomplete', 418);
        $this->assertSame(418, $teapot->statusCode);
        $this->assertSame(418, $teapot->getCode());
    }

    public function test_the_default_factory_maps_401_422_and_everything_else(): void
    {
        $factory = new DefaultExceptionFactory;
        $this->assertInstanceOf(ExceptionFactory::class, $factory);

        $unauthorized = $factory->fromResponse(FakeHttpClient::json(['error' => ['message' => 'Invalid API key']], 401));
        $this->assertInstanceOf(AuthenticationException::class, $unauthorized);
        $this->assertSame('Invalid API key', $unauthorized->getMessage());

        $invalid = $factory->fromResponse(FakeHttpClient::json(['errors' => ['x' => ['y']]], 422));
        $this->assertInstanceOf(ValidationException::class, $invalid);
        $this->assertSame(['x' => ['y']], $invalid->getErrors());

        foreach ([400, 402, 404, 429, 500, 503] as $status) {
            $other = $factory->fromResponse(FakeHttpClient::json(['error' => ['code' => 'C', 'message' => 'm']], $status));
            $this->assertSame(ProofAgeException::class, $other::class);
            $this->assertSame($status, $other->getCode());
            $this->assertSame('C', $other->getErrorCode());
        }
    }

    public function test_a_custom_factory_can_substitute_its_own_classes(): void
    {
        $custom = new class extends ProofAgeException {};
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

        $this->assertSame($custom, $factory->fromResponse(FakeHttpClient::json([], 500)));
    }
}
