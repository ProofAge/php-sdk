<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Enums;

/**
 * The `status` field of a verification, from the API response and the webhook body.
 *
 * DOCUMENTS_REQUIRED is not stored on the verification itself: the API reports it while
 * the latest attempt is waiting for document photos. Read the field with tryFrom(), not
 * from(), so a status added to the API later maps to null instead of throwing.
 */
enum VerificationStatus: string
{
    case CREATED = 'created';
    case STARTED = 'started';
    case SUBMITTED = 'submitted';
    case RESUBMISSION_REQUESTED = 'resubmission_requested';
    case APPROVED = 'approved';
    case DECLINED = 'declined';
    case ABANDONED = 'abandoned';
    case EXPIRED = 'expired';
    case REVIEW = 'review';
    case DOCUMENTS_REQUIRED = 'documents_required';
}
