<?php

declare(strict_types=1);

/*
 * Copies the app's generated OpenAPI spec into this package's bundled resources.
 * Source defaults to the sibling app repo; override with PROOFAGE_OPENAPI_SRC.
 * Regenerate the source first in the app: `cd developer-docs && npm run generate:openapi`.
 */

$src = getenv('PROOFAGE_OPENAPI_SRC')
    ?: __DIR__.'/../../proofageapp/developer-docs/public/openapi.json';
$dest = __DIR__.'/../resources/openapi.json';

if (! is_file($src)) {
    fwrite(STDERR, "Source spec not found: {$src}\n");
    fwrite(STDERR, "Run `npm run generate:openapi` in the app, or set PROOFAGE_OPENAPI_SRC.\n");
    exit(1);
}

if (! is_dir(dirname($dest)) && ! mkdir($concurrent = dirname($dest), 0755, true) && ! is_dir($concurrent)) {
    fwrite(STDERR, "Could not create directory: {$concurrent}\n");
    exit(1);
}

if (! copy($src, $dest)) {
    fwrite(STDERR, "Copy failed: {$src} -> {$dest}\n");
    exit(1);
}

fwrite(STDOUT, "Synced spec: {$src} -> {$dest}\n");
