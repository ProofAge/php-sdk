<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Tests\Enums;

use PHPUnit\Framework\TestCase;
use ProofAge\Sdk\Enums\WebhookEvent;

class WebhookEventTest extends TestCase
{
    public function test_event_values_match_proofage_webhook_payloads(): void
    {
        $this->assertSame('status.updated', WebhookEvent::STATUS_UPDATED->value);
        $this->assertSame('data.updated', WebhookEvent::DATA_UPDATED->value);
        $this->assertCount(2, WebhookEvent::cases());
    }

    public function test_a_data_updated_body_is_recognised_and_one_without_event_or_an_unknown_one_is_not_an_error(): void
    {
        $body = json_decode('{"verification_id":"v-1","event":"data.updated","status":"approved","external_id":null,"external_metadata":null,"reason":null,"timestamp":"2026-10-08T09:30:00+00:00","document":{"type":"id","issuing_country":"FR","issuing_subdivision":null,"fields":{"date_of_birth":"1988-02-11"}},"changed_fields":["date_of_birth"]}', true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(WebhookEvent::DATA_UPDATED, WebhookEvent::tryFrom($body['event']));
        $this->assertSame(['date_of_birth'], $body['changed_fields']);

        // An absent event means status.updated; an event added later maps to null, not to an exception.
        $this->assertSame(WebhookEvent::STATUS_UPDATED, WebhookEvent::tryFrom($body['missing'] ?? 'status.updated'));
        $this->assertNull(WebhookEvent::tryFrom('something.new'));
    }
}
