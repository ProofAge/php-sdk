<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Exceptions;

use ProofAge\Sdk\Http\Response;

/** 401 -> AuthenticationException, 422 -> ValidationException, everything else -> ProofAgeException. */
final class DefaultExceptionFactory implements ExceptionFactory
{
    public function fromResponse(Response $response): ProofAgeException
    {
        if ($response->status() === 401) {
            return AuthenticationException::fromResponse($response);
        }

        if ($response->status() === 422) {
            return ValidationException::fromResponse($response);
        }

        return ProofAgeException::fromResponse($response);
    }

    public function configuration(string $message, ?\Throwable $previous = null): ProofAgeException
    {
        return new ProofAgeException($message, 0, $previous);
    }
}
