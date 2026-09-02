<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Signing;

/**
 * The only implementation of the ProofAge request-signing canonical forms.
 *
 * - JSON / no-file requests: `METHOD + /{version}/{path}[?query] + rawBody`
 * - Multipart requests:      `METHOD/{version}/{path}[?query]\n{fields as RFC 3986 query, ksorted recursively}\n{comma-joined sorted sha256(file) hashes}`
 *
 * Both are pinned by resources/hmac-vectors.json, which the server executes too.
 */
final class Signer
{
    public function __construct(private readonly string $secretKey) {}

    /**
     * METHOD + path + rawBody. The bytes passed here must be the bytes the transport sends.
     */
    public function signRaw(string $method, string $path, string $rawBody = ''): string
    {
        return hash_hmac('sha256', self::canonicalRaw($method, $path, $rawBody), $this->secretKey);
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  list<string>  $fileHashes  sha256 hex digests of the file bytes sent, in any order
     */
    public function signMultipart(string $method, string $path, array $fields, array $fileHashes): string
    {
        return hash_hmac('sha256', self::canonicalMultipart($method, $path, $fields, $fileHashes), $this->secretKey);
    }

    public static function canonicalRaw(string $method, string $path, string $rawBody = ''): string
    {
        return strtoupper($method).$path.$rawBody;
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  list<string>  $fileHashes
     */
    public static function canonicalMultipart(string $method, string $path, array $fields, array $fileHashes): string
    {
        $fieldsString = http_build_query(self::canonicalizeFields($fields), '', '&', PHP_QUERY_RFC3986);

        sort($fileHashes);

        return strtoupper($method).$path."\n".$fieldsString."\n".implode(',', $fileHashes);
    }

    /**
     * ksort recursively, so nested field order never affects the signature.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    public static function canonicalizeFields(array $fields): array
    {
        ksort($fields);

        foreach ($fields as $key => $value) {
            if (is_array($value)) {
                $fields[$key] = self::canonicalizeFields($value);
            }
        }

        return $fields;
    }

    /**
     * The query string exactly as the server sees it when it signs.
     *
     * Reproduces Symfony\Component\HttpFoundation\Request::normalizeQueryString(): parse the
     * string with HeaderUtils::parseQuery() semantics, ksort() the top level once (not
     * recursively, which is why canonicalizeFields() is not reused here), then rebuild
     * with http_build_query(..., PHP_QUERY_RFC3986). Anything else signs a different string
     * than the server verifies.
     */
    public static function normalizeQueryString(string $query): string
    {
        if ($query === '') {
            return '';
        }

        $parsed = self::parseQuery($query);
        ksort($parsed);

        return http_build_query($parsed, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Port of Symfony\Component\HttpFoundation\HeaderUtils::parseQuery() with $ignoreBrackets
     * false and '&' as the separator: parse_str() semantics for values and bracket arrays,
     * but keys survive intact (parse_str() alone would turn `a.b` into `a_b`).
     *
     * @return array<string, mixed>
     */
    private static function parseQuery(string $query): array
    {
        $encoded = [];

        foreach (explode('&', $query) as $pair) {
            if (false !== $i = strpos($pair, "\0")) {
                $pair = substr($pair, 0, $i);
            }

            if (false === $i = strpos($pair, '=')) {
                $key = urldecode($pair);
                $pair = '';
            } else {
                $key = urldecode(substr($pair, 0, $i));
                $pair = substr($pair, $i);
            }

            if (false !== $i = strpos($key, "\0")) {
                $key = substr($key, 0, $i);
            }

            $key = ltrim($key, ' ');

            if (false === $i = strpos($key, '[')) {
                $encoded[] = bin2hex($key).$pair;
            } else {
                $encoded[] = bin2hex(substr($key, 0, $i)).rawurlencode(substr($key, $i)).$pair;
            }
        }

        parse_str(implode('&', $encoded), $decoded);

        $result = [];

        foreach ($decoded as $key => $value) {
            $key = (string) $key;

            if (false !== $i = strpos($key, '_')) {
                $result[substr_replace($key, (string) hex2bin(substr($key, 0, $i)).'[', 0, 1 + $i)] = $value;
            } else {
                $result[(string) hex2bin($key)] = $value;
            }
        }

        return $result;
    }
}
