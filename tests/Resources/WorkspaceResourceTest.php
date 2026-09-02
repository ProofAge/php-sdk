<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Tests\Resources;

use PHPUnit\Framework\TestCase;
use ProofAge\Sdk\Client;
use ProofAge\Sdk\Http\Request;
use ProofAge\Sdk\Resources\WorkspaceResource;
use ProofAge\Sdk\Testing\FakeHttpClient;

class WorkspaceResourceTest extends TestCase
{
    private FakeHttpClient $fake;

    /** @param array<string, mixed> $fakeResponses */
    private function makeFakedClient(array $fakeResponses): Client
    {
        $this->fake = new FakeHttpClient($fakeResponses);

        return new Client([
            'api_key' => 'test-api-key',
            'secret_key' => 'test-secret-key',
            'base_url' => 'https://api.test.com',
            'version' => 'v1',
        ], $this->fake);
    }

    public function test_workspace_returns_a_resource_bound_to_the_client(): void
    {
        $client = $this->makeFakedClient([]);

        $this->assertInstanceOf(WorkspaceResource::class, $client->workspace());
    }

    public function test_get_returns_workspace_data(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/v1/workspace' => FakeHttpClient::json([
                'id' => 'ws_123',
                'name' => 'My Workspace',
                'webhook_url' => 'https://example.com/webhook',
            ]),
        ]);

        $result = $client->workspace()->get();

        $this->assertEquals('ws_123', $result['id']);
        $this->assertEquals('My Workspace', $result['name']);

        $this->fake->assertSent(function (Request $request) {
            return $request->method === 'GET'
                && str_contains($request->url, '/v1/workspace');
        });
    }

    public function test_get_consent_returns_consent_data(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/v1/consent' => FakeHttpClient::json([
                'id' => 'con_456',
                'version' => 2,
                'text' => 'I consent to age verification.',
            ]),
        ]);

        $result = $client->workspace()->getConsent();

        $this->assertEquals('con_456', $result['id']);
        $this->assertEquals(2, $result['version']);

        $this->fake->assertSent(function (Request $request) {
            return $request->method === 'GET'
                && str_contains($request->url, '/v1/consent');
        });
    }
}
