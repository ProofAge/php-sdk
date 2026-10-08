<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Enums;

/**
 * The `event` field of a webhook body: what happened.
 *
 * STATUS_UPDATED is every webhook that reports a status change. DATA_UPDATED is sent when a
 * tenant corrects document fields the reader got wrong: `status` is then the current one,
 * unchanged, `document` holds the corrected values and `changed_fields` names what changed.
 * A body without `event` (a retry of a delivery created before the field existed) means
 * STATUS_UPDATED. Read the field with tryFrom(), not from(), so an event added later maps to
 * null instead of throwing.
 */
enum WebhookEvent: string
{
    case STATUS_UPDATED = 'status.updated';
    case DATA_UPDATED = 'data.updated';
}
