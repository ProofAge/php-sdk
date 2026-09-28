<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Tests;

use PHPUnit\Framework\TestCase;
use ProofAge\Sdk\Client;

/**
 * Client::VERSION is what every request reports in X-ProofAge-Sdk and User-Agent. A release is
 * the commit that renames CHANGELOG.md's "Unreleased" heading to "X.Y.Z - date" (composer.json
 * has no version field; the tag and Packagist follow that commit), so the constant must equal the
 * newest released heading. Bump both in the release commit.
 */
class VersionTest extends TestCase
{
    public function test_the_version_constant_matches_the_newest_release_in_the_changelog(): void
    {
        $changelog = (string) file_get_contents(__DIR__.'/../CHANGELOG.md');

        $this->assertSame(1, preg_match('/^## (\d+\.\d+\.\d+) - \d{4}-\d{2}-\d{2}$/m', $changelog, $match), 'CHANGELOG.md has no "## X.Y.Z - YYYY-MM-DD" heading');
        $this->assertSame(
            $match[1],
            Client::VERSION,
            "Client::VERSION is not the newest release in CHANGELOG.md ({$match[1]}): bump it in the release commit.",
        );
    }

    public function test_composer_json_carries_no_version_to_drift_from(): void
    {
        $composer = json_decode((string) file_get_contents(__DIR__.'/../composer.json'), true, 512, JSON_THROW_ON_ERROR);

        $this->assertArrayNotHasKey('version', $composer);
    }
}
