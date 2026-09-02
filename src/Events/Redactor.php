<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Events;

use ProofAge\Sdk\Http\Body\FilePart;
use ProofAge\Sdk\Http\Body\MultipartBody;
use ProofAge\Sdk\Http\Body\RawBody;

/**
 * What the events show instead of the real thing.
 *
 * The SDK transmits document photos and selfies, the workspace API key, and a
 * signature that - since API signing carries no timestamp or nonce - replays a
 * logged request verbatim. So events mask X-API-Key and X-HMAC-Signature, reduce a
 * JSON body to its size and hash, and reduce files to metadata. Scalar form fields
 * (type, side, document, ...) are not secret and stay verbatim.
 */
final class Redactor
{
    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    public static function headers(array $headers): array
    {
        foreach ($headers as $name => $value) {
            if (strcasecmp($name, 'X-API-Key') === 0) {
                $headers[$name] = '****'.substr($value, -4);
            } elseif (strcasecmp($name, 'X-HMAC-Signature') === 0) {
                $headers[$name] = substr($value, 0, 8).'...';
            }
        }

        return $headers;
    }

    /**
     * @return array{kind: 'none'}|array{kind: 'json', bytes: int, sha256: string}|array{kind: 'multipart', fields: array<string, mixed>, files: list<array{name: string, filename: string, bytes: int, sha256: string}>}
     */
    public static function body(RawBody|MultipartBody|null $body): array
    {
        if ($body === null) {
            return ['kind' => 'none'];
        }

        if ($body instanceof RawBody) {
            return ['kind' => 'json', 'bytes' => strlen($body->bytes), 'sha256' => hash('sha256', $body->bytes)];
        }

        return [
            'kind' => 'multipart',
            'fields' => $body->fields,
            'files' => array_map(static fn (FilePart $part): array => [
                'name' => $part->name,
                'filename' => $part->filename,
                'bytes' => strlen($part->contents),
                'sha256' => $part->sha256(),
            ], $body->files),
        ];
    }
}
