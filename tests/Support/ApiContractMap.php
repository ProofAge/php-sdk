<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Tests\Support;

class ApiContractMap
{
    /**
     * SDK method -> API operation contract.
     *
     * `path` matches the bundled openapi.json path template (no version prefix).
     * `request` / `response` are the TOP-LEVEL field-name sets the SDK exchanges; the
     * response sets are the authoritative (authored) contract and double as the source
     * for the `@return` PHPDoc shapes and AGENTS.md.
     *
     * @return array<string, array{method: string, path: string, operationId: string, request: list<string>, response: list<string>}>
     */
    public static function operations(): array
    {
        return [
            'workspace.get' => [
                'method' => 'GET', 'path' => '/workspace', 'operationId' => 'getWorkspace',
                'request' => [],
                'response' => ['id', 'name', 'flow_type', 'mode', 'age_mode', 'age_threshold', 'verification_type', 'redirect_url', 'webhook_url', 'allow_expired_documents', 'allow_duplicate_accounts'],
            ],
            'workspace.getConsent' => [
                'method' => 'GET', 'path' => '/consent', 'operationId' => 'getConsent',
                'request' => [],
                'response' => ['id', 'version', 'text_sha256', 'url'],
            ],
            'verifications.create' => [
                'method' => 'POST', 'path' => '/verifications', 'operationId' => 'createVerification',
                'request' => ['fingerprint', 'callback_url', 'external_id', 'external_metadata', 'metadata'],
                'response' => ['id', 'external_id', 'external_metadata', 'redirect_url', 'status', 'reason', 'consent_accepted_at', 'created_at', 'updated_at', 'url'],
            ],
            'verifications.find' => [
                'method' => 'GET', 'path' => '/verifications/{verification}', 'operationId' => 'getVerification',
                'request' => [],
                'response' => ['id', 'external_id', 'external_metadata', 'redirect_url', 'status', 'reason', 'consent_accepted_at', 'created_at', 'updated_at', 'duplicate_check'],
            ],
            'verifications.acceptConsent' => [
                'method' => 'POST', 'path' => '/verifications/{verification}/consent', 'operationId' => 'acceptConsent',
                'request' => ['consent_version_id', 'text_sha256', 'device', 'in_app_browser', 'in_iframe', 'referrer', 'camera_permission', 'camera_policy_allowed'],
                'response' => ['consent_version_id', 'consent_accepted_at'],
            ],
            'verifications.uploadMedia' => [
                'method' => 'POST', 'path' => '/verifications/{verification}/media', 'operationId' => 'uploadMedia',
                'request' => ['file', 'type', 'side', 'document', 'fingerprint', 'head_turn_step', 'capture_resolution', 'device_info', 'liveness_telemetry'],
                'response' => ['message'],
            ],
            'verifications.submit' => [
                'method' => 'POST', 'path' => '/verifications/{verification}/submit', 'operationId' => 'submitVerification',
                'request' => [],
                'response' => ['message'],
            ],
            'verifications.document' => [
                'method' => 'GET', 'path' => '/verifications/{verification}/document', 'operationId' => 'getVerificationDocument',
                'request' => [],
                'response' => ['document', 'media', 'meta'],
            ],
            'verifications.downloadMedia' => [
                'method' => 'GET', 'path' => '/verifications/{verification}/media/{media}', 'operationId' => 'downloadVerificationMedia',
                'request' => [],
                'response' => [],
            ],
            'verifications.estimation' => [
                'method' => 'GET', 'path' => '/verifications/{verification}/estimation', 'operationId' => 'getVerificationEstimation',
                'request' => [],
                'response' => ['verification_id', 'attempt_id', 'age_threshold', 'gender'],
            ],
            'verifications.blockFace' => [
                'method' => 'POST', 'path' => '/verifications/{verification}/blocked-face', 'operationId' => 'blockVerificationFace',
                'request' => ['reason', 'reason_code'],
                'response' => [],
            ],
        ];
    }
}
