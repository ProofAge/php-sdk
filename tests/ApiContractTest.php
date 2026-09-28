<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Tests;

use PHPUnit\Framework\TestCase;
use ProofAge\Sdk\Resources\WorkspaceResource;
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

            $specFields = $this->schemaProperties($this->requestSchema($op['path'], $op['method']));
            sort($specFields);
            $mapFields = $op['request'];
            sort($mapFields);

            $this->assertSame($mapFields, $specFields, "Request fields for [{$name}] drifted from the spec.");
        }
    }

    public function test_the_upload_request_is_multipart(): void
    {
        $content = $this->spec['paths']['/verifications/{verification}/media']['post']['requestBody']['content'] ?? [];

        $this->assertSame(['multipart/form-data'], array_keys($content), 'uploadMedia() sends multipart/form-data; the spec must say so.');
    }

    public function test_response_fields_match_the_spec_for_describable_endpoints(): void
    {
        $checked = [];

        foreach (ApiContractMap::operations() as $name => $op) {
            if ($op['response'] === null) {
                continue;
            }

            $specFields = $this->responseProperties($op['path'], $op['method'], $op['responseStatus'] ?? null);

            if ($specFields === []) {
                // The spec does not describe a JSON body here (the media download's bytes).
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
            ['verifications.acceptConsent', 'verifications.create', 'verifications.document', 'verifications.estimation', 'verifications.find', 'workspace.get', 'workspace.getConsent'],
            $checked,
            'The set of spec-describable response endpoints changed. If the generator improved, extend response parity coverage.'
        );
    }

    public function test_endpoints_the_sdk_treats_as_bodiless_have_no_json_success_body_in_the_spec(): void
    {
        $bodiless = [];

        foreach (ApiContractMap::operations() as $name => $op) {
            if ($op['response'] !== null) {
                continue;
            }

            $bodiless[] = $name;
            $responses = $this->spec['paths'][$op['path']][strtolower($op['method'])]['responses'] ?? [];
            $successes = array_filter($responses, static fn (mixed $code): bool => str_starts_with((string) $code, '2'), ARRAY_FILTER_USE_KEY);

            $this->assertNotSame([], $successes, "The spec describes no success response for [{$name}].");

            foreach ($successes as $code => $response) {
                $this->assertArrayNotHasKey('application/json', $response['content'] ?? [], "The spec gives [{$name}] a JSON {$code} body; the SDK method returns null. Update the method, its @return and the map.");
            }
        }

        sort($bodiless);
        $this->assertSame(['verifications.blockFace', 'verifications.submit', 'verifications.uploadMedia'], $bodiless);
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

    /**
     * The request body schema, whichever of JSON or multipart the spec declares.
     *
     * @return array<string, mixed>
     */
    public function test_the_consent_version_is_typed_as_the_integer_the_api_sends(): void
    {
        // The name-only checks above let `version: string` drift from the API's integer.
        $schema = $this->spec['paths']['/consent']['get']['responses']['200']['content']['application/json']['schema'];
        $this->assertSame('integer', $schema['properties']['version']['type'] ?? null);

        $doc = (string) (new \ReflectionMethod(WorkspaceResource::class, 'getConsent'))->getDocComment();
        $this->assertStringContainsString('version: int,', $doc);
        $this->assertStringNotContainsString('version: string', (string) file_get_contents(dirname(__DIR__).'/AGENTS.md'));
    }

    private function requestSchema(string $path, string $method): array
    {
        $content = $this->spec['paths'][$path][strtolower($method)]['requestBody']['content'] ?? [];

        return $content['application/json']['schema'] ?? $content['multipart/form-data']['schema'] ?? [];
    }

    /**
     * Top-level fields of the first 2xx JSON body the spec describes with properties. A
     * create answers 201; its 200 entry is an anyOf the SDK never receives (the native
     * client's compact format), which yields no properties and is passed over.
     *
     * @return list<string>
     */
    private function responseProperties(string $path, string $method, ?string $status = null): array
    {
        $responses = $this->spec['paths'][$path][strtolower($method)]['responses'] ?? [];

        foreach ($responses as $code => $response) {
            if (! str_starts_with((string) $code, '2') || ($status !== null && (string) $code !== $status)) {
                continue;
            }
            $schema = $response['content']['application/json']['schema'] ?? null;
            if ($schema === null) {
                continue;
            }

            $properties = $this->schemaProperties($schema);

            if ($properties !== []) {
                return $properties;
            }
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
