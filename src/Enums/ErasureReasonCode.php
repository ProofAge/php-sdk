<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Enums;

/**
 * Why a verification's personal data is being erased, sent as `reason_code` on
 * DELETE /verifications/{verification}/personal-data.
 *
 * Required by the API. `OTHER` also requires a `reason` that explains it.
 */
enum ErasureReasonCode: string
{
    /** The verified person exercised their right to erasure. */
    case DATA_SUBJECT_REQUEST = 'data_subject_request';

    /** You are erasing it on your own initiative, not the person's. */
    case CUSTOMER_REQUEST = 'customer_request';

    /** Your retention window for the verification elapsed. */
    case RETENTION_POLICY = 'retention_policy';

    /** The session was a test or a synthetic run, not a real person. */
    case TEST_DATA = 'test_data';

    /** Anything the other codes do not cover; explain it in `reason`, which it requires. */
    case OTHER = 'other';
}
