<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Enums;

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
}
