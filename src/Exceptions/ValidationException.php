<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Exceptions;

/** HTTP 422. getErrors() reads the top-level `errors` array of the response. */
class ValidationException extends ProofAgeException
{
    /** @var array<string, mixed> */
    protected array $errors = [];

    /** @return array<string, mixed> */
    public function getErrors(): array
    {
        if (empty($this->errors) && $this->response) {
            $data = $this->response->json();

            if (is_array($data) && isset($data['errors']) && is_array($data['errors'])) {
                $this->errors = $data['errors'];
            }
        }

        return $this->errors;
    }
}
