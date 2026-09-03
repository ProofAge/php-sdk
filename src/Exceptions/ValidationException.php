<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Exceptions;

use ProofAge\Sdk\Exceptions\Concerns\HasValidationErrors;

/** HTTP 422. getErrors() reads the top-level `errors` array of the response. */
class ValidationException extends ProofAgeException
{
    use HasValidationErrors;
}
