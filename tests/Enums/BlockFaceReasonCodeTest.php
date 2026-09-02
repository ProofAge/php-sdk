<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Tests\Enums;

use PHPUnit\Framework\TestCase;
use ProofAge\Sdk\Enums\BlockFaceReasonCode;

class BlockFaceReasonCodeTest extends TestCase
{
    public function test_reason_code_values_match_the_blocked_face_endpoint(): void
    {
        $this->assertSame('presentation_attack', BlockFaceReasonCode::PRESENTATION_ATTACK->value);
        $this->assertSame('fraudulent_document', BlockFaceReasonCode::FRAUDULENT_DOCUMENT->value);
        $this->assertSame('scam_or_abuse', BlockFaceReasonCode::SCAM_OR_ABUSE->value);
        $this->assertSame('underage', BlockFaceReasonCode::UNDERAGE->value);
        $this->assertSame('other', BlockFaceReasonCode::OTHER->value);
    }

    public function test_the_cases_match_the_codes_the_bundled_spec_accepts(): void
    {
        $spec = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2).'/resources/openapi.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame(
            $spec['components']['schemas']['BlockedFaceReasonCode']['enum'],
            array_column(BlockFaceReasonCode::cases(), 'value'),
            'The enum drifted from the codes the API accepts. Run `composer run sync-spec` and reconcile.',
        );
    }
}
