<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Enums;

enum WebhookReason: string
{
    case AmlBlocklistFaceMatch = 'aml.blocklist.face_match';
    case AmlBlocklistDeviceMatch = 'aml.blocklist.device_match';

    public static function isAmlBlocklist(?string $reason): bool
    {
        return in_array($reason, [
            self::AmlBlocklistFaceMatch->value,
            self::AmlBlocklistDeviceMatch->value,
        ], true);
    }
}
