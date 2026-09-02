<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Exceptions;

use ProofAge\Sdk\Http\Response;

/**
 * The one seam through which Client turns a non-2xx response into an exception.
 * A host framework implements it to have its own subclasses thrown, so a `catch` on
 * either its class or the SDK parent matches.
 */
interface ExceptionFactory
{
    public function fromResponse(Response $response): ProofAgeException;
}
