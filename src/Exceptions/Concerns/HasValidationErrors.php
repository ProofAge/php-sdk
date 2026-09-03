<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Exceptions\Concerns;

/**
 * The body of a 422 exception.
 *
 * Lives in a trait because PHP has single inheritance and a host framework's
 * ValidationException has to descend from that framework's own base class to
 * keep `catch` working there, so it cannot inherit this from the SDK class.
 */
trait HasValidationErrors
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
