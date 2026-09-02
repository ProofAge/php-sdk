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

    /**
     * Build the exception for a failure that never reached HTTP and so has no
     * Response: an incomplete configuration, or a request body that will not
     * encode. Routed through the seam for the same reason as fromResponse() —
     * a host framework's `catch` must match these too.
     */
    public function configuration(string $message, ?\Throwable $previous = null): ProofAgeException;
}
