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
    }
}
