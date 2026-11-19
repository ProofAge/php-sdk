<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Tests\Support;

use ProofAge\Sdk\Http\Request;

/**
 * The signature a request must carry now that every one is timestamped: the HMAC of
 * `{X-Timestamp}.{legacy canonical}`, with the timestamp read from the request itself.
 */
final class TimestampedSignature
{
    public static function forRequest(Request $sent, string $canonical, string $secret): string
    {
        return hash_hmac('sha256', (string) $sent->header('X-Timestamp').'.'.$canonical, $secret);
    }

    /** @param  array<string, string>  $echoHeaders  lower-cased header names, as the echo server reports them */
    public static function forEcho(array $echoHeaders, string $canonical, string $secret): string
    {
        return hash_hmac('sha256', $echoHeaders['x-timestamp'].'.'.$canonical, $secret);
    }
}
