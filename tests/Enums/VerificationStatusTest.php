<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Tests\Enums;

use PHPUnit\Framework\TestCase;
use ProofAge\Sdk\Enums\VerificationStatus;

class VerificationStatusTest extends TestCase
{
    public function test_status_values_match_proofage_verification_payloads(): void
    {
        $this->assertSame('created', VerificationStatus::CREATED->value);
        $this->assertSame('started', VerificationStatus::STARTED->value);
        $this->assertSame('submitted', VerificationStatus::SUBMITTED->value);
        $this->assertSame('resubmission_requested', VerificationStatus::RESUBMISSION_REQUESTED->value);
        $this->assertSame('approved', VerificationStatus::APPROVED->value);
        $this->assertSame('declined', VerificationStatus::DECLINED->value);
        $this->assertSame('abandoned', VerificationStatus::ABANDONED->value);
        $this->assertSame('expired', VerificationStatus::EXPIRED->value);
        $this->assertSame('review', VerificationStatus::REVIEW->value);
        $this->assertSame('documents_required', VerificationStatus::DOCUMENTS_REQUIRED->value);
    }

    public function test_every_status_the_api_can_report_maps_and_an_unknown_one_does_not_throw(): void
    {
        foreach (['created', 'started', 'submitted', 'resubmission_requested', 'approved', 'declined', 'abandoned', 'expired', 'review', 'documents_required'] as $status) {
            $this->assertInstanceOf(VerificationStatus::class, VerificationStatus::tryFrom($status), $status);
        }

        $this->assertCount(10, VerificationStatus::cases());
        $this->assertNull(VerificationStatus::tryFrom('pending'));
    }
}
