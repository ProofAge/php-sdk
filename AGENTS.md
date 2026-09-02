# ProofAge PHP SDK — API contract for agents

This package wraps the ProofAge v1 HTTP API. Methods on `$client->workspace()` and
`$client->verifications($id)` (`ProofAge\Sdk\Client`) return decoded JSON as `array|null`.
The exact request and response shape of every method is below and in the `@param`/`@return`
PHPDoc on `src/Resources/`. A machine-readable spec ships at `resources/openapi.json`
(authoritative for endpoints + request bodies; response schemas there are incomplete by
generator limitation — the shapes below are authoritative for responses). This SDK is the
single source of truth for endpoint paths and request shapes; `proofage/laravel-client` is
an integration layer on top of it.

All requests send `X-API-Key` and `X-HMAC-Signature`. Base URL is
`{base_url}/{version}` (defaults `https://api.proofage.xyz/v1`).

## Auth / HMAC

- `X-API-Key`: workspace API key (plaintext; the server SHA256-hashes it).
- `X-HMAC-Signature`: hex HMAC-SHA256 with the workspace secret key over a canonical string:
  - JSON / no-file requests: `METHOD + /{version}/{path} + rawJsonBody`.
  - Multipart (file) requests: `METHOD/{version}/{path}\n{sorted fields as RFC3986 query}\n{comma-joined sorted sha256(file) hashes}`.
  - When the request carries a query string, the signed path is `/{version}/{path}?{query}` with
    the query **normalized, not passed through**, exactly as the server normalizes it before
    verifying (Symfony `Request::normalizeQueryString()`: parse, `ksort` the top-level keys once,
    rebuild with `http_build_query(..., PHP_QUERY_RFC3986)`). `ProofAge\Sdk\Signing\Signer::normalizeQueryString()`
    implements this and sends the same normalized string in the URL. No current endpoint takes
    query parameters.
- `ProofAge\Sdk\Signing\Signer` is the only implementation of both canonical forms. They are
  pinned by the golden vectors in `resources/hmac-vectors.json`, which ship in the dist so the
  server's test suite can execute the same fixture. A change to either format is a change to that
  fixture first, in a reviewed commit.

## Endpoints

### GET /workspace — `$client->workspace()->get()`
Request: none.
Response: `{ id: string, name: string, flow_type: string, mode: string, age_mode: string|null, age_threshold: int|null, verification_type: string, redirect_url: string|null, webhook_url: string|null, allow_expired_documents: bool, allow_duplicate_accounts: bool }`

### GET /consent — `$client->workspace()->getConsent()`
Request: none.
Response: `{ id: int, version: string, text_sha256: string, url: string }`

### POST /verifications — `$client->verifications()->create($data)`
Request: `{ fingerprint?: string(64), callback_url?: url(<=2048), external_id?: string(<=255), external_metadata?: object, metadata?: object }`
Response: `{ id: string, external_id: string|null, external_metadata: object|null, redirect_url: string|null, status: string, reason: string|null, consent_accepted_at: string|null, created_at: string, updated_at: string, url: string }`
Errors: `402` `{ code: "PAYMENT_METHOD_REQUIRED", message, free_verifications_remaining, trial_ends_at, trial_active }`.

### GET /verifications/{verification} — `$client->verifications($id)->find($id)` / `->get()`
Request: none.
Response: same as create **without** `url`.

### POST /verifications/{verification}/consent — `$client->verifications($id)->acceptConsent($data)`
Request: `{ consent_version_id: int, text_sha256: string(64 hex) }`
Response: `{ consent_version_id: int, consent_accepted_at: string }`

### POST /verifications/{verification}/media — `$client->verifications($id)->uploadMedia($data)` (multipart)
Request: `{ file: path|\SplFileInfo|FilePart, type: "selfie"|"liveness_selfie"|"document", side?: "front"|"back" (req. if type=document), document?: "id"|"driver_license"|"passport"|"residence_permit" (req. if type=document), fingerprint?: string(64), head_turn_step?: int(0..10), capture_resolution?: json-string, device_info?: json-string }`
Response: `{ message: string }`. Requires consent accepted first. A `file` path that does not exist throws `\InvalidArgumentException` before anything is sent.

### POST /verifications/{verification}/submit — `$client->verifications($id)->submit()`
Request: none.
Response: `{ message: string }`. Error: `422 { error: { code, message } }`.

### GET /verifications/{verification}/document — `$client->verifications($id)->document()`
Request: none.
Response: `{ document: { fields: { first_name: string|null, last_name: string|null, date_of_birth: string|null (YYYY-MM-DD), document_number: string|null } }, media: [ { id: string, type: "selfie"|"document_front"|"document_back", url: string|null } ], meta: { attempt_id: string|null } }`. `url` is the download endpoint for that media, null when it has been purged or is past retention; fetch the bytes with `downloadMedia(media[].id)`.

### GET /verifications/{verification}/media/{media} — `$client->verifications($id)->downloadMedia($mediaId)`
Request: none. `{media}` is `media[].id` from document().
Response: the image bytes, `Content-Type` from the file (e.g. `image/jpeg`). `downloadMedia()` returns a PSR-7 `StreamInterface`; `downloadMediaTo($mediaId, $path)` streams to disk and returns the path. Downloads do not retry HTTP failures — 429 included — because they run from a queue whose backoff owns the wait; raise `download_retry_attempts` (default 1) to retry connection failures only. Error: `404 { error: { code: "MEDIA_NOT_FOUND", message } }` when the media is purged, past retention, or not part of this verification. `url` is null when the media has been purged or is past retention, so check it before downloading rather than treating a 404 as normal.

### GET /verifications/{verification}/estimation — `$client->verifications($id)->estimation()`
Request: none.
Response: `{ verification_id: string, attempt_id: string|null, age_threshold: { minimum: int|null, passed: bool|null, confidence: float|null }, gender: { value: 0|1|null, confidence: float|null }|null }` (gender value: 0=female, 1=male).

### POST /verifications/{verification}/blocked-face — `$client->verifications($id)->blockFace($data)`
Request: `{ reason_code?: string, reason?: string(<=1000) }`.
Response: `204 No Content` (method returns `null`).

## Enums

- `status`: one of `created`, `started`, `submitted`, `resubmission_requested`, `approved`, `declined`, `abandoned`, `expired`, `review` (the `ProofAge\Sdk\Enums\VerificationStatus` cases), or `documents_required` — surfaced from the latest attempt's state (an `AttemptStatus`), not a `VerificationStatus` case. Map the `status` field with `VerificationStatus::tryFrom()` and handle `documents_required` explicitly.
- `reason_code` (request field on `blockFace`): one of `presentation_attack` (spoof: screen, print or mask), `fraudulent_document` (forged, edited, or not a real document), `scam_or_abuse` (identity may be genuine — blocked for behaviour on your platform), `underage`, `other` (explain in `reason`) — the `ProofAge\Sdk\Enums\BlockFaceReasonCode` cases. Optional over the API, mandatory in the ProofAge consoles: send it whenever a person made the decision, or the block cannot be told apart from an automated one in reporting.
- `reason` (on `declined` / `resubmission_requested`): dotted codes from the server's reason catalog — illustrative examples: `aml.blocklist.face_match`, `document.face.mismatch`, `verification.age_threshold.failed`. `ProofAge\Sdk\Enums\WebhookReason` models only the AML blocklist codes; treat `reason` as an open string.

## Errors

Non-2xx responses throw `ProofAge\Sdk\Exceptions\ProofAgeException` (`getCode()` is the HTTP
status, `getErrorCode()`/`getErrorData()` come from the body's `error` object, `getResponse()`
is the `ProofAge\Sdk\Http\Response`): `AuthenticationException` for 401,
`ValidationException` (with `getErrors()`) for 422. A failure below HTTP — connection refused,
DNS, TLS, timeout — throws `TransportException`, which never carries a response. Every SDK
exception implements `ProofAge\Sdk\Exceptions\ExceptionInterface`.

## Outbound webhook (ProofAge → your `callback_url` / workspace webhook URL)

Headers: `X-Auth-Client` (api key), `X-Timestamp` (unix seconds), `X-HMAC-Signature`
(= hex HMAC-SHA256 of `{timestamp}.{rawJsonBody}` with the active secret key),
`X-ProofAge-Webhook-Delivery-Id`. Verify with `ProofAge\Sdk\Webhooks\WebhookVerifier`
(`verifyHeaders($headers, $rawBody)` throws `WebhookVerificationException` with `errorCode`
`MISSING_SIGNATURE`, `MISSING_TIMESTAMP`, `MISSING_AUTH_CLIENT`, `INVALID_AUTH_CLIENT`,
`TIMESTAMP_TOO_OLD` or `INVALID_SIGNATURE`, `statusCode` 401, and `toArray()` as the response
body); in Laravel, the `proofage.verify_webhook` middleware of `proofage/laravel-client` wraps
the same sequence.

Body:
```
{
  "verification_id": string,
  "status": string,
  "external_id": string|null,
  "external_metadata": object|null,
  "reason": string|null,                       // only on resubmission_requested / declined
  "timestamp": string (ISO8601),
  "duplicate_detected"?: true,                 // present only when a duplicate was found
  "duplicate_of"?: { "verification_id": string, "external_id": string|null },
  "fingerprint_signals"?: { "ip_address"?, "ip_country_code"?, "ip_timezone"?, "device_timezone"?, ... },
  "manual_moderation"?: { "action": "approve"|"decline", "reason": string, "source": string,
                          "performed_by": string, "source_status"?: string|null, "source_reason"?: string|null }
}
```

## Keeping this in sync

This contract is drift-tested against `resources/openapi.json` via `tests/ApiContractTest.php`,
so it stays aligned with the API. Maintainers refreshing it after an API change: run
`composer run sync-spec` (copies the app's generated spec into `resources/`), then make
`tests/ApiContractTest.php` pass by updating `tests/Support/ApiContractMap.php`, the
`@param`/`@return` shapes in `src/Resources/`, and this file together. See the SDK
contract-sync runbook in the ProofAge app repo (`developer-docs/README.md`), the single source of
truth for all SDKs.
