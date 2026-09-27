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
 *
 * Field values are normalized once, here, to what the server will read back: a null is
 * dropped, `true`/`false` become "1"/"0", numbers become their string form, and an array
 * left empty is dropped. The signer and every transport read the same normalized $fields,
 * so a transport that casts each value to a string (Guzzle's multipart, Illuminate's
 * attach()) sends exactly what was signed. Without this a null went out as "" and `false`
 * as "", while the signature, built with http_build_query(), omitted the null and rendered
 * `false` as "0" - and the server answered 401 INVALID_SIGNATURE.
 */
final class MultipartBody
{
    /**
     * The normalized form fields: every leaf a string, no nulls, no empty arrays.
     *
     * @var array<string, mixed>
     */
    public readonly array $fields;

    /**
     * @param  array<string, mixed>  $fields
     * @param  list<FilePart>  $files
     *
     * @throws \InvalidArgumentException for a top-level field name PHP would rename, a file
     *                                   field name used twice, or a value that is not a
     *                                   scalar, null, \Stringable or an array of those
     */
    public function __construct(
        array $fields,
        public readonly array $files,
    ) {
        foreach (array_keys($fields) as $name) {
            self::assertFieldName((string) $name, 'form');
        }

        $this->fields = self::normalize($fields);

        $seen = [];

        foreach ($files as $part) {
            self::assertFieldName($part->name, 'file');

            if (isset($seen[$part->name])) {
                throw new \InvalidArgumentException(sprintf('Multipart file field name "%s" is used twice; PHP keeps one upload per field name, so the server would verify one file hash where two were signed', $part->name));
            }

            $seen[$part->name] = true;
        }
    }

    /**
     * @template TKey of array-key
     *
     * @param  array<TKey, mixed>  $fields
     * @return array<TKey, mixed>
     */
    private static function normalize(array $fields): array
    {
        $out = [];

        foreach ($fields as $key => $value) {
            if ($value === null) {
                continue;
            }

            if (is_array($value)) {
                $value = self::normalize($value);

                if ($value !== []) {
                    $out[$key] = $value;
                }

                continue;
            }

            if (is_bool($value)) {
                $out[$key] = $value ? '1' : '0';

                continue;
            }

            if (is_scalar($value) || $value instanceof \Stringable) {
                $out[$key] = (string) $value;

                continue;
            }

            throw new \InvalidArgumentException(sprintf('Multipart field "%s" must be a scalar, null, a Stringable or an array of those, %s given', $key, get_debug_type($value)));
        }

        return $out;
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
