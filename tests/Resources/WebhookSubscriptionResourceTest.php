<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Tests\Resources;

use PHPUnit\Framework\TestCase;
use ProofAge\Sdk\Client;
use ProofAge\Sdk\Exceptions\ProofAgeException;
use ProofAge\Sdk\Exceptions\ValidationException;
use ProofAge\Sdk\Resources\WebhookSubscriptionResource;
use ProofAge\Sdk\Testing\FakeHttpClient;

class WebhookSubscriptionResourceTest extends TestCase
{
    private FakeHttpClient $fake;

    /**
     * A subscription as the API renders it (WebhookSubscriptionResource).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function subscription(string $id, array $overrides = []): array
    {
        return array_merge([
            'id' => $id,
            'url' => 'https://hooks.zapier.com/hooks/standard/12345678/abcdef/',
            'statuses' => null,
            'include_document_data' => false,
            'created_at' => '2026-10-08T12:00:00+00:00',
        ], $overrides);
    }

    /** @param array<string, mixed> $fakeResponses */
    private function makeFakedClient(array $fakeResponses): Client
    {
        $this->fake = new FakeHttpClient($fakeResponses);

        return new Client([
            'api_key' => 'test-api-key',
            'secret_key' => 'test-secret-key',
            'base_url' => 'https://api.test.com',
            'version' => 'v1',
            'retry_delay' => 0,
        ], $this->fake);
    }

    public function test_webhook_subscriptions_returns_a_resource_bound_to_the_client(): void
    {
        $this->assertInstanceOf(WebhookSubscriptionResource::class, $this->makeFakedClient([])->webhookSubscriptions());
    }

    public function test_create_posts_the_subscription_and_returns_it(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/v1/webhook-subscriptions' => FakeHttpClient::json(self::subscription('sub_1', [
                'statuses' => ['approved', 'declined'],
                'include_document_data' => true,
            ]), 201),
        ]);

        $result = $client->webhookSubscriptions()->create([
            'url' => 'https://hooks.zapier.com/hooks/standard/12345678/abcdef/',
            'statuses' => ['approved', 'declined'],
            'include_document_data' => true,
        ]);

        $this->assertSame(self::subscription('sub_1', ['statuses' => ['approved', 'declined'], 'include_document_data' => true]), $result);

        $expectedBody = json_encode([
            'url' => 'https://hooks.zapier.com/hooks/standard/12345678/abcdef/',
            'statuses' => ['approved', 'declined'],
            'include_document_data' => true,
        ]);
        $sent = $this->fake->sent()[0];
        $this->assertSame('POST', $sent->method);
        $this->assertSame('https://api.test.com/v1/webhook-subscriptions', $sent->url);
        $this->assertSame($expectedBody, $sent->body?->bytes);
        $this->assertSame('application/json', $sent->header('Content-Type'));
        $this->assertSame(
            hash_hmac('sha256', 'POST/v1/webhook-subscriptions'.$expectedBody, 'test-secret-key'),
            $sent->header('X-HMAC-Signature'),
        );
    }

    public function test_create_returns_null_statuses_for_a_subscription_to_every_status(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/v1/webhook-subscriptions' => FakeHttpClient::json(self::subscription('sub_1'), 201),
        ]);

        $result = $client->webhookSubscriptions()->create(['url' => 'https://example.com/hook']);

        $this->assertNull($result['statuses']);
        $this->assertFalse($result['include_document_data']);
        $this->assertSame('{"url":"https:\/\/example.com\/hook"}', $this->fake->sent()[0]->body?->bytes);
    }

    public function test_create_is_not_retried_after_a_server_error(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/v1/webhook-subscriptions' => FakeHttpClient::json(['message' => 'Server Error'], 502),
        ]);

        try {
            $client->webhookSubscriptions()->create(['url' => 'https://example.com/hook']);
            $this->fail('Expected a ProofAgeException.');
        } catch (ProofAgeException $e) {
            $this->assertSame(502, $e->getCode());
        }

        $this->fake->assertSentCount(1);
    }

    public function test_the_subscription_limit_is_a_validation_exception_carrying_its_code(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/*' => FakeHttpClient::json(['error' => [
                'code' => 'WEBHOOK_SUBSCRIPTION_LIMIT',
                'message' => 'A workspace can have at most 50 webhook subscriptions. Delete one first.',
            ]], 422),
        ]);

        try {
            $client->webhookSubscriptions()->create(['url' => 'https://example.com/hook']);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame('WEBHOOK_SUBSCRIPTION_LIMIT', $e->getErrorCode());
        }
    }

    public function test_an_invalid_url_is_a_validation_exception_with_field_errors(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/*' => FakeHttpClient::json([
                'message' => 'The url field must be a valid URL.',
                'errors' => ['url' => ['The url field must be a valid URL.']],
            ], 422),
        ]);

        try {
            $client->webhookSubscriptions()->create(['url' => 'not a url']);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame(['url' => ['The url field must be a valid URL.']], $e->getErrors());
        }
    }

    public function test_list_returns_the_subscriptions(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/v1/webhook-subscriptions' => FakeHttpClient::json(['data' => [
                self::subscription('sub_2', ['statuses' => ['review']]),
                self::subscription('sub_1'),
            ]]),
        ]);

        $result = $client->webhookSubscriptions()->list();

        $this->assertSame(['sub_2', 'sub_1'], array_column($result['data'], 'id'));
        $this->assertSame(['review'], $result['data'][0]['statuses']);

        $sent = $this->fake->sent()[0];
        $this->assertSame('GET', $sent->method);
        $this->assertNull($sent->body);
        $this->assertSame(hash_hmac('sha256', 'GET/v1/webhook-subscriptions', 'test-secret-key'), $sent->header('X-HMAC-Signature'));
    }

    public function test_delete_sends_a_signed_delete_and_accepts_the_empty_204(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/v1/webhook-subscriptions/sub_1' => FakeHttpClient::raw('', 204),
        ]);

        $client->webhookSubscriptions()->delete('sub_1');

        $sent = $this->fake->sent()[0];
        $this->assertSame('DELETE', $sent->method);
        $this->assertSame('https://api.test.com/v1/webhook-subscriptions/sub_1', $sent->url);
        $this->assertNull($sent->body);
        $this->assertSame(hash_hmac('sha256', 'DELETE/v1/webhook-subscriptions/sub_1', 'test-secret-key'), $sent->header('X-HMAC-Signature'));
    }

    public function test_delete_of_an_unknown_subscription_throws_a_404(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/*' => FakeHttpClient::json(['message' => 'Resource not found'], 404),
        ]);

        try {
            $client->webhookSubscriptions()->delete('sub_missing');
            $this->fail('Expected a ProofAgeException.');
        } catch (ProofAgeException $e) {
            $this->assertSame(404, $e->getCode());
            $this->assertSame('Resource not found', $e->getMessage());
        }

        $this->fake->assertSentCount(1);
    }

    public function test_delete_is_retried_after_a_server_error_as_an_idempotent_method(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/v1/webhook-subscriptions/sub_1' => [
                FakeHttpClient::json(['message' => 'Server Error'], 503),
                FakeHttpClient::raw('', 204),
            ],
        ]);

        $client->webhookSubscriptions()->delete('sub_1');

        $this->fake->assertSentCount(2);
    }
}
