# ProofAge PHP SDK — API contract for agents

This package wraps the ProofAge v1 HTTP API. Methods on `$client->workspace()` and
`$client->verifications($id)` (`ProofAge\Sdk\Client`) return decoded JSON as `array|null`.
The exact request and response shape of every method is below and in the `@param`/`@return`
PHPDoc on `src/Resources/`. A machine-readable spec ships at `resources/openapi.json`
(authoritative for endpoints and request bodies; it describes most responses too, and
`tests/ApiContractTest.php` checks the top-level fields below against it wherever it does).
Responses are never wrapped in `data`. This SDK is the
single source of truth for endpoint paths and request shapes; `proofage/laravel-client` is
an integration layer on top of it.

All requests send `X-API-Key` and `X-HMAC-Signature` to `{base_url}/{version}/{path}`, plus
`X-ProofAge-Sdk: php/{Client::VERSION}` (a wrapper's `sdk_tokens` go first, e.g.
`laravel/0.9.0 php/0.3.0`) and, unless one is set, `User-Agent: ProofAge-PHP/{version} (PHP {PHP_VERSION})`;
neither is signed. The
`base_url` config key is required and has no default — use `https://api.proofage.xyz`, with no
path; `version` defaults to `v1`.

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
Request: `{ fingerprint?: string(64), callback_url?: url(<=2048), external_id?: string(<=255), external_metadata?: object, metadata?: object, page_url?: string(<=8192) }`
Response: `201 { id: string, external_id: string|null, external_metadata: object|null, redirect_url: string|null, status: string, reason: string|null, duplicate_check: { checked: bool, duplicate_count: int, duplicates: [ { verification_id: string|null, external_id: string|null, similarity_score: float|null, verified_at: string|null } ] }, erasure: { erased_at: string, scope: string, reason: string|null, requested_via: string|null }|null, consent_accepted_at: string|null, created_at: string, updated_at: string, url: string }`
`callback_url` is where the person's browser goes when they finish (returned as `redirect_url`, falling back to the workspace's redirect URL); it is **not** a webhook target. `page_url` is the page the flow was started on; only scheme, host and path are kept. `url` is the link the person opens. `duplicate_count` counts every face match; `duplicates` may be shorter. `erasure` is null until the personal data is erased.
Errors: `402` `{ code: "PAYMENT_METHOD_REQUIRED", message, free_verifications_remaining, trial_ends_at, trial_active }` (flat, not nested under `error`).

### GET /verifications/{verification} — `$client->verifications($id)->find($id)` / `->get()`
Request: none.
Response: same as create **without** `url`.

### POST /verifications/{verification}/consent — `$client->verifications($id)->acceptConsent($data)`
Request: `{ consent_version_id: int, text_sha256: string(64 hex), device?: { platform?: string, screen?: string, language?: string, timezone?: string, hardware_concurrency?: number, device_memory?: number }, in_app_browser?: string(<=64), camera_permission?: "granted"|"denied"|"prompt"|"unsupported", camera_policy_allowed?: bool, in_iframe?: bool, referrer?: string(<=512) }` — everything after `text_sha256` is optional browser context a capture widget reports; a server-side integration leaves it out.
Response: `{ consent_version_id: int, consent_accepted_at: string }`

### POST /verifications/{verification}/media — `$client->verifications($id)->uploadMedia($data)` (multipart)
Request: `{ file: path|\SplFileInfo|FilePart, type: "selfie"|"liveness_selfie"|"document", side?: "front"|"back" (req. if type=document), document?: "id"|"driver_license"|"passport"|"residence_permit" (req. if type=document), fingerprint?: string(64), head_turn_step?: int(0..10), capture_resolution?: json-string, device_info?: json-string, liveness_telemetry?: json-string }`
Response: `200` with an **empty body**; the method returns `null`. Requires consent accepted first. A `file` path that does not exist throws `\InvalidArgumentException` before anything is sent. A null field is not sent and a boolean is sent as `1`/`0`, so the multipart signature holds.
Errors: `422 { code, message }` (flat) when the image fails a quality check — `FACE_NOT_FOUND` and the other quality codes, `MAX_ATTEMPTS_REACHED`; `500 { code: "VALIDATION_SERVICE_UNAVAILABLE", message }`; `422 { message, errors }` for invalid fields.

### POST /verifications/{verification}/submit — `$client->verifications($id)->submit()`
Request: none.
Response: `200` with an **empty body**; the method returns `null`. The outcome arrives by webhook or through `get()`.
Error: `422 { error: { code, message } }` when the verification is not `started` or required media is missing (e.g. `MISSING_REQUIRED_MEDIA`).

### GET /verifications/{verification}/document — `$client->verifications($id)->document()`
Request: none.
Response: `{ document: { fields: { first_name: string|null, last_name: string|null, date_of_birth: string|null (YYYY-MM-DD), document_number: string|null } }, media: [ { id: string, type: "selfie"|"document_front"|"document_back", url: string|null } ], meta: { attempt_id: string|null } }`. `url` is the download endpoint for that media, null when it has been purged or is past retention; fetch the bytes with `downloadMedia(media[].id)`.

### GET /verifications/{verification}/media/{media} — `$client->verifications($id)->downloadMedia($mediaId)`
Request: none. `{media}` is `media[].id` from document().
Response: the image bytes, `Content-Type` from the file (e.g. `image/jpeg`). The SDK sends `Accept: application/json, */*;q=0.8` so a 403/404 comes back as JSON. `downloadMedia()` returns a PSR-7 `StreamInterface`; `downloadMediaTo($mediaId, $path)` streams to disk and returns the path. Downloads do not retry HTTP failures — 429 included — because they run from a queue whose backoff owns the wait; raise `download_retry_attempts` (default 1) to retry connection failures only. Error: `404 { error: { code: "MEDIA_NOT_FOUND", message } }` when the media is purged, past retention, or not part of this verification. `url` is null when the media has been purged or is past retention, so check it before downloading rather than treating a 404 as normal.

### GET /verifications/{verification}/estimation — `$client->verifications($id)->estimation()`
Request: none.
Response: `{ verification_id: string, attempt_id: string|null, age_threshold: { minimum: int|null, passed: bool|null, confidence: float|null }, gender: { value: 0|1|null, confidence: float|null }|null }` (gender value: 0=female, 1=male).

### POST /verifications/{verification}/blocked-face — `$client->verifications($id)->blockFace($data)`
Request: `{ reason_code?: string, reason?: string(<=1000) }`.
Response: `204 No Content` (method returns `null`).

## Retries

`GET` requests are retried (`retry_attempts`, default 3) after a transport failure, a 429, or a
3xx/5xx. A `POST` is retried only when repeating it cannot make the server act twice: a connection
failure before the request was sent (`TransportException::requestMayHaveBeenSent()` is false) or a
429 carrying `Retry-After`. A 5xx or a timeout on a `POST` is thrown at once.

## Enums

- `status`: one of `created`, `started`, `submitted`, `resubmission_requested`, `approved`, `declined`, `abandoned`, `expired`, `review`, `documents_required` — the `ProofAge\Sdk\Enums\VerificationStatus` cases. `documents_required` is reported by the API while the latest attempt waits for document photos; it is not stored on the verification. Map the field with `VerificationStatus::tryFrom()`, not `from()`, so a status added to the API later reads as `null` instead of throwing.
- `reason_code` (request field on `blockFace`): one of `presentation_attack` (spoof: screen, print or mask), `fraudulent_document` (forged, edited, or not a real document), `scam_or_abuse` (identity may be genuine — blocked for behaviour on your platform), `underage`, `other` (explain in `reason`) — the `ProofAge\Sdk\Enums\BlockFaceReasonCode` cases. Optional over the API, mandatory in the ProofAge consoles: send it whenever a person made the decision, or the block cannot be told apart from an automated one in reporting.
- `reason` (on `declined` / `resubmission_requested`): dotted codes from the server's reason catalog — illustrative examples: `aml.blocklist.face_match`, `document.face.mismatch`, `verification.age_threshold.failed`. `ProofAge\Sdk\Enums\WebhookReason` models only the AML blocklist codes; treat `reason` as an open string.

## Errors

Non-2xx responses throw `ProofAge\Sdk\Exceptions\ProofAgeException` (`getCode()` is the HTTP
status, `getResponse()` is the `ProofAge\Sdk\Http\Response`): `AuthenticationException` for 401,
`ValidationException` (with `getErrors()`) for 422. The API sends several error bodies and the
exception reads each:

| Body | Sent for | `getMessage()` | `getErrorCode()` | `getErrorData()` |
|---|---|---|---|---|
| `{ error: { code, message } }` | most errors: 401, 404 `MEDIA_NOT_FOUND`, 422 on submit, 429 `RATE_LIMIT` | `error.message` | `error.code` | the `error` object |
| `{ code, message, ... }` | 402 `PAYMENT_METHOD_REQUIRED`; upload quality errors: 422 `FACE_NOT_FOUND`, `MAX_ATTEMPTS_REACHED`, ..., 500 `VALIDATION_SERVICE_UNAVAILABLE` | `message` | `code` | the whole body |
| `{ message, errors }` | 422 request validation | `message` | `null` | the whole body; `getErrors()` is `errors` |
| `{ message }` | 403 access denied, 404 `Resource not found` | `message` | `null` | the whole body |

A failure below HTTP — connection refused, DNS, TLS, timeout — throws `TransportException`, which
never carries a response. Every SDK exception implements `ProofAge\Sdk\Exceptions\ExceptionInterface`.

## Outbound webhook (ProofAge → the workspace's webhook URL)

Webhooks go to the workspace's `webhook_url` (see `GET /workspace`), never to create's
`callback_url`, which is only the browser redirect. They are signed with the workspace's
**active** secret key; API requests are accepted with any secret key that has not been deleted,
so verify webhooks with the active one.

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
  "duplicate_count"?: int,                     // with duplicate_detected: every match found
  "duplicate_of"?: { "verification_id": string, "external_id": string|null },   // the first match
  "fingerprint_signals"?: { "ip_address"?, "ip_country_code"?, "ip_timezone"?, "device_timezone"?, ... },  // present only when signals were collected
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
