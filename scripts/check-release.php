<?php

declare(strict_types=1);

/**
 * Prints the versions Packagist actually serves, and refuses a version that is
 * already taken.
 *
 * The git tags in this repository are NOT a reliable picture of what has been
 * released: several published versions (0.2.9 through 0.5.0) have no tag behind
 * them any more. Reading `git tag` to pick the next version once produced a tag
 * on a number Packagist already served from a different commit — which would
 * have changed the contents of a published release. Ask the registry instead.
 *
 * Usage:
 *   php scripts/check-release.php            # show what is published
 *   php scripts/check-release.php v0.7.0     # also verify that version is free
 */
$package = json_decode((string) file_get_contents(__DIR__.'/../composer.json'), true)['name'];
$url = "https://repo.packagist.org/p2/{$package}.json";

$body = @file_get_contents($url);

if ($body === false) {
    fwrite(STDERR, "Could not reach Packagist ({$url}). Check the published versions by hand before tagging.\n");
    exit(2);
}

$payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
$released = [];

foreach (reset($payload['packages']) as $release) {
    $released[ltrim((string) $release['version'], 'v')] = substr((string) $release['source']['reference'], 0, 8);
}

uksort($released, 'version_compare');
$latest = array_key_last($released);

echo "Published on Packagist ({$package}):\n";

foreach (array_slice($released, -5, 5, true) as $version => $reference) {
    echo "  {$version}\t{$reference}\n";
}

[$major, $minor, $patch] = array_map('intval', explode('.', $latest));

echo "\nLatest published: {$latest}\n";
echo "Next patch: {$major}.{$minor}.".($patch + 1)."  |  next minor: {$major}.".($minor + 1).".0\n";

$candidate = $argv[1] ?? null;

if ($candidate === null) {
    exit(0);
}

$candidate = ltrim($candidate, 'v');

if (isset($released[$candidate])) {
    fwrite(STDERR, "\nREFUSED: {$candidate} is already published, from commit {$released[$candidate]}.\n");
    fwrite(STDERR, "Tagging it again would change the contents of a released version.\n");
    exit(1);
}

if (version_compare($candidate, $latest, '<')) {
    fwrite(STDERR, "\nREFUSED: {$candidate} is below the published {$latest}, so it would never resolve as latest.\n");
    exit(1);
}

echo "\n{$candidate} is free and above the published latest.\n";
