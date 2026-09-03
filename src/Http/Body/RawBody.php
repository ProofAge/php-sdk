<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Http\Body;

/**
 * A body sent as-is. The bytes here are the bytes signed and the bytes sent; there is
 * no array form on the request that a transport could re-serialise differently.
 */
final class RawBody
{
    public function __construct(
        public readonly string $bytes,
        public readonly string $contentType = 'application/json',
    ) {}

    /**
     * print_r() and var_dump() show the size and sha256 of the bytes, never the bytes: a
     * JSON body carries names and dates of birth.
     *
     * @return array{contentType: string, bytes: int, sha256: string}
     */
    public function __debugInfo(): array
    {
        return [
            'contentType' => $this->contentType,
            'bytes' => strlen($this->bytes),
            'sha256' => hash('sha256', $this->bytes),
        ];
    }
}
