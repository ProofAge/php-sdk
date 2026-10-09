<?php

declare(strict_types=1);

/*
 * Copies the published OpenAPI spec into this package's bundled resources.
 * Source defaults to https://docs.proofage.net/openapi.json. PROOFAGE_OPENAPI_SRC overrides
 * it with another URL or a local file, for example the docs repo's openapi.json before it
 * is published (the docs repo regenerates it from the app with scripts/sync_openapi.py).
 */

$src = getenv('PROOFAGE_OPENAPI_SRC') ?: 'https://docs.proofage.net/openapi.json';
$dest = __DIR__.'/../resources/openapi.json';

$isUrl = (bool) preg_match('#^https?://#', $src);

if (! $isUrl && ! is_file($src)) {
    fwrite(STDERR, "Source spec not found: {$src}\n");
    exit(1);
}

$body = @file_get_contents($src);

if ($body === false) {
    fwrite(STDERR, "Could not read {$src}\n");
    exit(1);
}

try {
    json_decode($body, false, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    fwrite(STDERR, "{$src} is not JSON: {$e->getMessage()}\n");
    exit(1);
}

if (! is_dir(dirname($dest)) && ! mkdir($concurrent = dirname($dest), 0755, true) && ! is_dir($concurrent)) {
    fwrite(STDERR, "Could not create directory: {$concurrent}\n");
    exit(1);
}

if (file_put_contents($dest, $body) === false) {
    fwrite(STDERR, "Write failed: {$dest}\n");
    exit(1);
}

fwrite(STDOUT, "Synced spec: {$src} -> {$dest}\n");
