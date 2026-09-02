<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Tests\Enums;

use PHPUnit\Framework\TestCase;
use ProofAge\Sdk\Enums\WebhookReason;

class WebhookReasonTest extends TestCase
{
    public function test_aml_blocklist_face_match_reason_value(): void
    {
        $this->assertSame('aml.blocklist.face_match', WebhookReason::AmlBlocklistFaceMatch->value);
    }

    public function test_aml_blocklist_device_match_reason_value(): void
    {
        $this->assertSame('aml.blocklist.device_match', WebhookReason::AmlBlocklistDeviceMatch->value);
    }

    public function test_it_identifies_aml_blocklist_reasons(): void
    {
        $this->assertTrue(WebhookReason::isAmlBlocklist('aml.blocklist.face_match'));
        $this->assertTrue(WebhookReason::isAmlBlocklist('aml.blocklist.device_match'));
        $this->assertFalse(WebhookReason::isAmlBlocklist('manual.decline'));
        $this->assertFalse(WebhookReason::isAmlBlocklist(null));
    }
}
