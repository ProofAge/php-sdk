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
}
