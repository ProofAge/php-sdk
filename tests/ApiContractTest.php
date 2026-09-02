<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Tests;

use PHPUnit\Framework\TestCase;
use ProofAge\Sdk\Tests\Support\ApiContractMap;

class ApiContractTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $spec;

    protected function setUp(): void
    {
        parent::setUp();

        $path = dirname(__DIR__).'/resources/openapi.json';
        $this->assertFileExists($path, 'Bundled OpenAPI spec is missing. Run `composer run sync-spec`.');
        $this->spec = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_every_sdk_operation_exists_in_the_spec(): void
    {
        foreach (ApiContractMap::operations() as $name => $op) {
            $specOp = $this->spec['paths'][$op['path']][strtolower($op['method'])] ?? null;
            $this->assertNotNull(
                $specOp,
                "SDK method [{$name}] targets {$op['method']} {$op['path']} which is missing from the bundled spec."
            );
        }
    }

    public function test_every_spec_operation_is_covered_by_an_sdk_method(): void
    {
        $mapped = [];
        foreach (ApiContractMap::operations() as $op) {
            $mapped[strtoupper($op['method']).' '.$op['path']] = true;
        }

        foreach ($this->spec['paths'] as $path => $methods) {
            foreach ($methods as $method => $operation) {
                $key = strtoupper($method).' '.$path;
                $this->assertArrayHasKey(
                    $key,
                    $mapped,
                    "Spec exposes {$key} but no SDK method covers it. Add it to ApiContractMap and the resource class."
                );
            }
        }
    }

    public function test_request_fields_match_the_spec(): void
    {
        foreach (ApiContractMap::operations() as $name => $op) {
            // Limitation (mirrors the response-parity skip): a new request body appearing on a currently body-less endpoint is not caught here — refresh the contract map when adding one.
            if ($op['request'] === []) {
                continue;
            }

            $specFields = $this->schemaProperties(
                $this->spec['paths'][$op['path']][strtolower($op['method'])]['requestBody']['content']['application/json']['schema'] ?? []
            );
            sort($specFields);
            $mapFields = $op['request'];
            sort($mapFields);

            $this->assertSame($mapFields, $specFields, "Request fields for [{$name}] drifted from the spec.");
        }
    }

    public function test_response_fields_match_the_spec_for_describable_endpoints(): void
    {
        $checked = [];

        foreach (ApiContractMap::operations() as $name => $op) {
            $specFields = $this->responseProperties($op['path'], $op['method']);

            if ($specFields === []) {
                // Scramble cannot describe this response (typed as integer/empty array).
                // Its shape is pinned by the authored @return PHPDoc + HTTP-fake tests instead.
                continue;
            }

            sort($specFields);
            $mapFields = $op['response'];
            sort($mapFields);

            $this->assertSame($mapFields, $specFields, "Response fields for [{$name}] drifted from the spec.");
            $checked[] = $name;
        }

        sort($checked);
        $this->assertSame(
            ['verifications.document', 'verifications.estimation', 'verifications.find', 'workspace.get'],
            $checked,
            'The set of spec-describable response endpoints changed. If the generator improved, extend response parity coverage.'
        );
    }

    public function test_agents_doc_covers_every_endpoint(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__).'/AGENTS.md');

        foreach (ApiContractMap::operations() as $name => $op) {
            $token = $op['method'].' '.$op['path'];
            $this->assertStringContainsString(
                $token,
                $doc,
                "AGENTS.md is missing the [{$name}] endpoint line ({$token})."
            );
        }
    }

    public function test_agents_doc_names_sdk_namespaces_only(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__).'/AGENTS.md');

        $this->assertStringNotContainsString('ProofAge\\Laravel\\', $doc);
        $this->assertStringContainsString('ProofAge\\Sdk\\Enums\\VerificationStatus', $doc);
        $this->assertStringContainsString('ProofAge\\Sdk\\Enums\\BlockFaceReasonCode', $doc);
        $this->assertStringContainsString('ProofAge\\Sdk\\Enums\\WebhookReason', $doc);
    }

    public function test_agents_doc_states_the_query_string_clause(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__).'/AGENTS.md');

        $this->assertStringContainsString('?{query}', $doc, 'AGENTS.md must document that a normalized query string is part of the signed path.');
    }

    /** @return list<string> */
    private function responseProperties(string $path, string $method): array
    {
        $responses = $this->spec['paths'][$path][strtolower($method)]['responses'] ?? [];

        foreach ($responses as $code => $response) {
            if (! str_starts_with((string) $code, '2')) {
                continue;
            }
            $schema = $response['content']['application/json']['schema'] ?? null;
            if ($schema === null) {
                continue;
            }

            return $this->schemaProperties($schema);
        }

        return [];
    }

    /**
     * Top-level property names of an OpenAPI schema, resolving $ref and merging allOf.
     *
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    private function schemaProperties(array $schema): array
    {
        if (isset($schema['$ref'])) {
            $ref = substr((string) $schema['$ref'], strlen('#/components/schemas/'));

            return $this->schemaProperties($this->spec['components']['schemas'][$ref] ?? []);
        }

        $properties = [];

        if (isset($schema['allOf']) && is_array($schema['allOf'])) {
            foreach ($schema['allOf'] as $sub) {
                $properties = array_merge($properties, $this->schemaProperties($sub));
            }
        }

        if (isset($schema['properties']) && is_array($schema['properties'])) {
            $properties = array_merge($properties, array_keys($schema['properties']));
        }

        return array_values(array_unique($properties));
    }
}
