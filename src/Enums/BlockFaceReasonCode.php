<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Enums;

/**
 * Why a face is being added to the blocklist, sent as `reason_code` on
 * POST /verifications/{verification}/blocked-face.
 *
 * Optional over the API and mandatory in the ProofAge consoles, so a block sent
 * without one cannot be told apart from an automated block when the blocklist is
 * later reported on. Send it whenever a person made the decision.
 */
enum BlockFaceReasonCode: string
{
    /** The selfie or document was photographed from a screen, a print, or a mask. */
    case PRESENTATION_ATTACK = 'presentation_attack';

    /** The document is forged, edited, or not a real identity document. */
    case FRAUDULENT_DOCUMENT = 'fraudulent_document';

    /** The identity may be genuine — the person is blocked for what they did on your platform. */
    case SCAM_OR_ABUSE = 'scam_or_abuse';

    /** The person is below the age the workspace verifies for. */
    case UNDERAGE = 'underage';

    /** Anything the other codes do not cover; explain it in `reason`. */
    case OTHER = 'other';
}
