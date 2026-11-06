<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Tests\Enums;

use PHPUnit\Framework\TestCase;
use ProofAge\Sdk\Enums\ErasureReasonCode;

class ErasureReasonCodeTest extends TestCase
{
    public function test_reason_code_values_match_the_personal_data_endpoint(): void
    {
        $this->assertSame('data_subject_request', ErasureReasonCode::DATA_SUBJECT_REQUEST->value);
        $this->assertSame('customer_request', ErasureReasonCode::CUSTOMER_REQUEST->value);
        $this->assertSame('retention_policy', ErasureReasonCode::RETENTION_POLICY->value);
        $this->assertSame('test_data', ErasureReasonCode::TEST_DATA->value);
        $this->assertSame('other', ErasureReasonCode::OTHER->value);
    }

    public function test_the_cases_match_the_codes_the_bundled_spec_accepts(): void
    {
        $spec = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2).'/resources/openapi.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        // Follow the request field's $ref rather than naming the schema, so a renamed
        // component still compares against the codes reason_code accepts.
        $ref = (string) ($spec['components']['schemas']['ErasePersonalDataRequest']['properties']['reason_code']['$ref'] ?? '');
        $name = str_replace('#/components/schemas/', '', $ref);

        $this->assertSame(
            $spec['components']['schemas'][$name]['enum'] ?? null,
            array_column(ErasureReasonCode::cases(), 'value'),
            'The enum drifted from the codes the API accepts. Run `composer run sync-spec` and reconcile.',
        );
    }
}
