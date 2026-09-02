<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Http;

use ProofAge\Sdk\Http\Body\MultipartBody;

/**
 * RFC 7578 multipart/form-data encoder shared by CurlHttpClient and Psr18HttpClient.
 *
 * Nested field arrays flatten to `name[key]` parts and scalars render the way
 * http_build_query() renders them (true => 1, false => 0, null skipped), so what the
 * server decodes back into $request->request->all() canonicalizes to the string that
 * was signed.
 */
final class MultipartEncoder
{
    private const CONTENT_TYPES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'heic' => 'image/heic',
        'heif' => 'image/heif',
        'bmp' => 'image/bmp',
        'tif' => 'image/tiff',
        'tiff' => 'image/tiff',
        'pdf' => 'application/pdf',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'mov' => 'video/quicktime',
        'json' => 'application/json',
        'txt' => 'text/plain',
    ];

    private readonly string $boundary;

    public function __construct(?string $boundary = null)
    {
        $this->boundary = $boundary ?? 'proofage'.bin2hex(random_bytes(16));
    }

    public function boundary(): string
    {
        return $this->boundary;
    }

    public function contentType(): string
    {
        return 'multipart/form-data; boundary='.$this->boundary;
    }

    public function encode(MultipartBody $body): string
    {
        $out = '';

        foreach (self::flatten($body->fields) as [$name, $value]) {
            $out .= "--{$this->boundary}\r\n"
                .'Content-Disposition: form-data; name="'.self::quote($name)."\"\r\n"
                ."\r\n{$value}\r\n";
        }

        foreach ($body->files as $part) {
            $out .= "--{$this->boundary}\r\n"
                .'Content-Disposition: form-data; name="'.self::quote($part->name).'"; filename="'.self::quote($part->filename)."\"\r\n"
                .'Content-Type: '.($part->contentType ?? self::guessContentType($part->filename))."\r\n"
                ."\r\n{$part->contents}\r\n";
        }

        return $out."--{$this->boundary}--\r\n";
    }

    /**
     * @param  array<int|string, mixed>  $fields
     * @return list<array{string, string}>
     */
    private static function flatten(array $fields, string $prefix = ''): array
    {
        $out = [];

        foreach ($fields as $key => $value) {
            if ($value === null) {
                continue;
            }

            $name = $prefix === '' ? (string) $key : $prefix.'['.$key.']';

            if (is_array($value)) {
                $out = [...$out, ...self::flatten($value, $name)];

                continue;
            }

            $out[] = [$name, self::scalar($value)];
        }

        return $out;
    }

    private static function scalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_scalar($value) || $value instanceof \Stringable) {
            return (string) $value;
        }

        throw new \InvalidArgumentException('Multipart field values must be scalars or arrays of scalars');
    }

    private static function quote(string $value): string
    {
        return str_replace(["\r", "\n", '"'], ['', '', '%22'], $value);
    }

    private static function guessContentType(string $filename): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return self::CONTENT_TYPES[$extension] ?? 'application/octet-stream';
    }
}
