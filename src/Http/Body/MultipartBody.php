<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Http\Body;

/**
 * Form fields plus file parts. The signature covers the fields and the file hashes,
 * never the encoded body, so each transport is free to encode it its own way.
 *
 * Top-level field names must be valid PHP variable names. The server canonicalizes
 * $_POST and $_FILES as PHP registered them, and PHP rewrites a name it does not like
 * (`a.b` and `a b` become `a_b`, ` lead` loses its space, `a[b` and `tags[]` nest), so
 * the name signed here would differ from the name the server signs and the request
 * would fail with a signature error the caller cannot diagnose. Nested keys are not
 * restricted: PHP keeps them verbatim inside the brackets. Two files under one field
 * name are rejected for the same reason: PHP keeps one upload per name, so two hashes
 * would be signed and one verified.
 */
final class MultipartBody
{
    /**
     * @param  array<string, mixed>  $fields
     * @param  list<FilePart>  $files
     *
     * @throws \InvalidArgumentException for a top-level field name PHP would rename, or a
     *                                   file field name used twice
     */
    public function __construct(
        public readonly array $fields,
        public readonly array $files,
    ) {
        foreach (array_keys($fields) as $name) {
            self::assertFieldName((string) $name, 'form');
        }

        $seen = [];

        foreach ($files as $part) {
            self::assertFieldName($part->name, 'file');

            if (isset($seen[$part->name])) {
                throw new \InvalidArgumentException(sprintf('Multipart file field name "%s" is used twice; PHP keeps one upload per field name, so the server would verify one file hash where two were signed', $part->name));
            }

            $seen[$part->name] = true;
        }
    }

    private static function assertFieldName(string $name, string $kind): void
    {
        if (preg_match('/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/', $name) !== 1) {
            throw new \InvalidArgumentException(sprintf('Multipart %s field name %s is not a valid PHP variable name; PHP would register it under a different name on the server and the signature would not match', $kind, json_encode($name, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
        }
    }

    /** @return list<string> sha256 hex digests, in file order */
    public function fileHashes(): array
    {
        return array_map(static fn (FilePart $part): string => $part->sha256(), $this->files);
    }
}
