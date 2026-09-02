<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Http\Body;

/**
 * Form fields plus file parts. The signature covers the fields and the file hashes,
 * never the encoded body, so each transport is free to encode it its own way.
 */
final class MultipartBody
{
    /**
     * @param  array<string, mixed>  $fields
     * @param  list<FilePart>  $files
     */
    public function __construct(
        public readonly array $fields,
        public readonly array $files,
    ) {}

    /** @return list<string> sha256 hex digests, in file order */
    public function fileHashes(): array
    {
        return array_map(static fn (FilePart $part): string => $part->sha256(), $this->files);
    }
}
