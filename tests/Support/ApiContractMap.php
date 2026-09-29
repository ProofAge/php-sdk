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
     * `response` is null when the API answers 2xx with no body at all (the method returns
     * null), and `[]` when the body is not JSON (the media download's bytes).
     *
     * `responseStatus` names the success status the SDK receives when the operation documents
     * more than one: createVerification also documents a 200 compact session, sent only to the
     * hosted widget's `X-ProofAge-Client: native` header, which this SDK never sends.
     *
     * @return array<string, array{method: string, path: string, operationId: string, request: list<string>, response: list<string>|null, responseStatus?: string}>
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
                'request' => ['callback_url', 'external_id', 'external_metadata', 'metadata'],
                'response' => ['id', 'external_id', 'external_metadata', 'redirect_url', 'status', 'reason', 'duplicate_check', 'erasure', 'consent_accepted_at', 'created_at', 'updated_at', 'url'],
                'responseStatus' => '201',
            ],
            'verifications.find' => [
                'method' => 'GET', 'path' => '/verifications/{verification}', 'operationId' => 'getVerification',
                'request' => [],
                'response' => ['id', 'external_id', 'external_metadata', 'redirect_url', 'status', 'reason', 'duplicate_check', 'erasure', 'consent_accepted_at', 'created_at', 'updated_at'],
            ],
            'verifications.acceptConsent' => [
                'method' => 'POST', 'path' => '/verifications/{verification}/consent', 'operationId' => 'acceptConsent',
                'request' => ['consent_version_id', 'text_sha256'],
                'response' => ['consent_version_id', 'consent_accepted_at'],
            ],
            'verifications.uploadMedia' => [
                'method' => 'POST', 'path' => '/verifications/{verification}/media', 'operationId' => 'uploadMedia',
                'request' => ['file', 'type', 'side', 'document'],
                'response' => null,
            ],
            'verifications.submit' => [
                'method' => 'POST', 'path' => '/verifications/{verification}/submit', 'operationId' => 'submitVerification',
                'request' => [],
                'response' => null,
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
                'response' => null,
            ],
        ];
    }
}
