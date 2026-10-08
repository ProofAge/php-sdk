# ProofAge PHP SDK — API contract for agents

This package wraps the ProofAge v1 HTTP API. Methods on `$client->workspace()`,
`$client->verifications($id)` and `$client->webhookSubscriptions()` (`ProofAge\Sdk\Client`) return
decoded JSON as `array|null`.
The exact request and response shape of every method is below and in the `@param`/`@return`
PHPDoc on `src/Resources/`. A machine-readable spec ships at `resources/openapi.json`
(authoritative for endpoints and request bodies; it describes most responses too, and
`tests/ApiContractTest.php` checks the top-level fields below against it wherever it does).
A single object is never wrapped in `data`; the two list endpoints answer `{ data: [...] }`. This SDK is the
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
    implements this and sends the same normalized string in the URL. `GET /verifications`
    (`list()`) is the one endpoint that takes query parameters: `list(['status' => ['approved', 'declined'], 'limit' => 50])`
    is sent and signed as `GET/v1/verifications?limit=50&status=approved%2Cdeclined`.
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
Response: `{ id: int, version: int, text_sha256: string, url: string }` (`version` is informational: accept consent with `id` and `text_sha256`)

### POST /verifications — `$client->verifications()->create($data)`
Request: `{ callback_url?: url(<=2048), external_id?: string(<=255), external_metadata?: object, metadata?: object }`
Response: `201 { id: string, external_id: string|null, external_metadata: object|null, redirect_url: string|null, status: string, reason: string|null, duplicate_check: { checked: bool, duplicate_count: int, duplicates: [ { verification_id: string|null, external_id: string|null, similarity_score: float|null, verified_at: string|null } ] }, erasure: { erased_at: string, scope: string, reason: string|null, requested_via: string|null }|null, consent_accepted_at: string|null, created_at: string, updated_at: string, url: string }`
`callback_url` is where the person's browser goes when they finish (returned as `redirect_url`, falling back to the workspace's redirect URL); it is **not** a webhook target. `url` is the link the person opens. `duplicate_count` counts every face match; `duplicates` may be shorter. `erasure` is null until the personal data is erased.
Errors: `402` `{ code: "PAYMENT_METHOD_REQUIRED", message, free_verifications_remaining, trial_ends_at, trial_active }` (flat, not nested under `error`).

### GET /verifications — `$client->verifications()->list($query)`
Request: query `{ status?: string|VerificationStatus|list<string|VerificationStatus>, external_id?: string, limit?: int(1..100, default 20), cursor?: string }`. A status list is sent comma-separated; a null entry and an empty list are left out.
Response: `{ data: [ <the find() shape> ], next_cursor: string|null }`, newest first.
`status` filters on the stored statuses (`created`, `started`, `submitted`, `resubmission_requested`, `approved`, `declined`, `abandoned`, `expired`, `review`); a verification reported as `documents_required` is stored as `started`, and `documents_required` itself is refused with a 422. `external_id` is an exact, case-sensitive match. Page by passing `next_cursor` back as `cursor` with the same filters until it is `null`.

### GET /verifications/{verification} — `$client->verifications($id)->find($id)` / `->get()`
Request: none.
Response: same as create **without** `url`.

### POST /verifications/{verification}/consent — `$client->verifications($id)->acceptConsent($data)`
Request: `{ consent_version_id: int, text_sha256: string(64 hex) }`: the `id` and `text_sha256` from `getConsent()`.
Response: `{ consent_version_id: int, consent_accepted_at: string }`

### POST /verifications/{verification}/media — `$client->verifications($id)->uploadMedia($data)` (multipart)
Request: `{ file: path|\SplFileInfo|FilePart, type: "selfie"|"document", side?: "front"|"back" (req. if type=document), document?: "id"|"driver_license"|"passport"|"residence_permit" (req. if type=document) }`
Response: `200` with an **empty body**; the method returns `null`. Requires consent accepted first. A `file` path that does not exist throws `\InvalidArgumentException` before anything is sent. A null field is not sent and a boolean is sent as `1`/`0`, so the multipart signature holds.
Errors: `422 { code, message }` (flat) when the image fails a quality check — `FACE_NOT_FOUND` and the other quality codes, `MAX_ATTEMPTS_REACHED`; `500 { code: "VALIDATION_SERVICE_UNAVAILABLE", message }`; `422 { message, errors }` for invalid fields.

### POST /verifications/{verification}/submit — `$client->verifications($id)->submit()`
Request: none.
Response: `200` with an **empty body**; the method returns `null`. The outcome arrives by webhook or through `get()`.
Error: `422 { error: { code, message } }` when the verification is not `started` or required media is missing (e.g. `MISSING_REQUIRED_MEDIA`).

### GET /verifications/{verification}/document — `$client->verifications($id)->document()`
Request: none.
Response: `{ document: { type?: string|null, issuing_country?: string|null, issuing_subdivision?: string|null, fields: { first_name: string|null, middle_name?: string|null, last_name: string|null, date_of_birth: string|null (YYYY-MM-DD), gender?: string|null, nationality?: string|null, place_of_birth?: string|null, address?: string|null, document_number: string|null, issue_date?: string|null (YYYY-MM-DD), expiry_date?: string|null (YYYY-MM-DD) } }, media: [ { id: string, type: "selfie"|"document_front"|"document_back", url: string|null } ], meta: { attempt_id: string|null } }`. `url` is the download endpoint for that media, null when it has been purged or is past retention; fetch the bytes with `downloadMedia(media[].id)`.

Document semantics. `null` means the field was not read or is not printed on that document (a passport has no address, many cards print no place of birth, the German identity card prints no sex). `type` is an open set (`passport`, `id`, `driver_license`, `residence_permit`, `other`): handle unknown values; `other` is a readable document that is not an identity card, passport, driving licence or residence permit, such as a health insurance card; `id` is the same value the upload endpoint takes. `issuing_subdivision` is the state or province of issuance as a bare code (`FL` with `US`; up to three uppercase letters or digits) or `null`: `null` when `issuing_country` is, filled today for US driving licences and ID cards, present on every workspace, and kept after erasure. `issuing_country` and `nationality` are ISO 3166-1 alpha-2 (`XK` for Kosovo) and can differ, for example on a residence permit. `gender` is `F`, `M` or `X`; `X` means the document states that the sex is unspecified, and `gender` is `null` on a Mexican voter card or driving licence created before 20 August 2026 17:00 UTC. `date_of_birth`, `issue_date` and `expiry_date` are `YYYY-MM-DD`: a document that prints only the month or year of expiry reports the last day of that period, a partially printed birth or issue date is `null`, and a permanent document (for example `PERMANENTE`) has a `null` `expiry_date`. `place_of_birth` is the printed text. `address` is the printed text as read (trimmed, `null` when empty, not parsed, may contain line breaks). Age workspaces receive only `type`, `issuing_country` and `first_name`, `last_name`, `date_of_birth`, `document_number`; the other seven keys are absent, not `null`.

### GET /verifications/{verification}/media/{media} — `$client->verifications($id)->downloadMedia($mediaId)`
Request: none. `{media}` is `media[].id` from document().
Response: the image bytes, `Content-Type` from the file (e.g. `image/jpeg`). The SDK sends `Accept: application/json, */*;q=0.8` so a 403/404 comes back as JSON. `downloadMedia()` returns a PSR-7 `StreamInterface`; `downloadMediaTo($mediaId, $path)` streams to disk and returns the path. Downloads do not retry HTTP failures — 429 included — because they run from a queue whose backoff owns the wait; raise `download_retry_attempts` (default 1) to retry connection failures only. Error: `404 { error: { code: "MEDIA_NOT_FOUND", message } }` when the media is purged, past retention, or not part of this verification. `url` is null when the media has been purged or is past retention, so check it before downloading rather than treating a 404 as normal.

### GET /verifications/{verification}/estimation — `$client->verifications($id)->estimation()`
Request: none.
Response: `{ verification_id: string, attempt_id: string|null, age_threshold: { minimum: int|null, passed: bool|null, confidence: float|null }, gender: { value: 0|1|null, confidence: float|null }|null }` (gender value: 0=female, 1=male).

### POST /verifications/{verification}/blocked-face — `$client->verifications($id)->blockFace($data)`
Request: `{ reason_code?: string, reason?: string(<=1000) }`.
Response: `204 No Content` (method returns `null`).

### POST /verifications/{verification}/test-outcome — `$client->verifications($id)->setTestOutcome($data)`
Request: `{ status: "approved"|"declined"|"review"|"resubmission_requested", reason?: string|null(<=1000) }`. `reason` is a note kept with a `resubmission_requested` outcome in the verification's history, not the decision `reason` code.
Response: the find() shape, with the new status.
Test workspaces only: finishes the verification without a person going through the widget, so an integration's handling of each outcome can be tested end to end, webhooks included. A verification nobody has opened is moved through `started` and `submitted` first, so the outcome is the only decision webhooks are sent for. Works from `created`, `started`, `submitted`, `review` and `resubmission_requested`.
Errors: `403 { error: { code: "TEST_WORKSPACE_ONLY", message } }` on a live workspace; `422 { error: { code: "INVALID_STATUS", message } }` when the verification is already final; `422 { message, errors }` for invalid fields.

### POST /webhook-subscriptions — `$client->webhookSubscriptions()->create($data)`
Request: `{ url: url(http/https, <=2048, public), statuses?: list<"approved"|"declined"|"resubmission_requested"|"review"|"abandoned"|"expired">|null, include_document_data?: bool (default false) }`.
Response: `201 { id: string, url: string, statuses: string[]|null, include_document_data: bool, created_at: string }`. `statuses` is `null` when the subscription receives every decision status.
A subscription (REST hook, e.g. Zapier) receives the decision webhooks in addition to the workspace webhook URL; see "Outbound webhook" below. A workspace can have up to 50. A private, local or cloud-metadata URL is refused.
Errors: `422 { error: { code: "WEBHOOK_SUBSCRIPTION_LIMIT", message } }` when the workspace already has 50; `422 { message, errors }` for invalid fields.

### GET /webhook-subscriptions — `$client->webhookSubscriptions()->list()`
Request: none.
Response: `{ data: [ { id: string, url: string, statuses: string[]|null, include_document_data: bool, created_at: string } ] }`, newest first, not paginated.

### DELETE /webhook-subscriptions/{subscription} — `$client->webhookSubscriptions()->delete($id)`
Request: none.
Response: `204 No Content`; the method returns `void`. Deliveries already queued are not sent.
Error: `404 { message: "Resource not found" }` when the id is not one of the workspace's subscriptions.

## Retries

`GET` and `DELETE` requests are retried (`retry_attempts`, default 3) after a transport failure, a 429, or a
3xx/5xx; a `delete()` whose first attempt went through answers the retry with a 404. A `POST` is retried only when repeating it cannot make the server act twice: a connection
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
| `{ error: { code, message } }` | most errors: 401, 403 `TEST_WORKSPACE_ONLY`, 404 `MEDIA_NOT_FOUND`, 422 on submit, 422 `INVALID_STATUS`, 422 `WEBHOOK_SUBSCRIPTION_LIMIT`, 429 `RATE_LIMIT` | `error.message` | `error.code` | the `error` object |
| `{ code, message, ... }` | 402 `PAYMENT_METHOD_REQUIRED`; upload quality errors: 422 `FACE_NOT_FOUND`, `MAX_ATTEMPTS_REACHED`, ..., 500 `VALIDATION_SERVICE_UNAVAILABLE` | `message` | `code` | the whole body |
| `{ message, errors }` | 422 request validation | `message` | `null` | the whole body; `getErrors()` is `errors` |
| `{ message }` | 403 access denied, 404 `Resource not found` | `message` | `null` | the whole body |

A failure below HTTP — connection refused, DNS, TLS, timeout — throws `TransportException`, which
never carries a response. Every SDK exception implements `ProofAge\Sdk\Exceptions\ExceptionInterface`.

## Outbound webhook (ProofAge → the workspace's webhook URL)

Webhooks go to the workspace's `webhook_url` (see `GET /workspace`) and to every webhook
subscription (`POST /webhook-subscriptions`), never to create's `callback_url`, which is only the
browser redirect. The workspace webhook is signed with the workspace's **active** secret key; API
requests are accepted with any secret key that has not been deleted, so verify webhooks with the
active one. A subscription's deliveries are signed with the secret key that signed its create
request while that key exists, and with the active one after it is deleted. A subscription
delivery has the same headers and body, except that without `include_document_data` it leaves out
`document`, `fingerprint_signals` and `manual_moderation.performed_by`, and only its `statuses` are
sent. A delivery answered with `410 Gone` deletes the subscription.

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
  "event"?: "status.updated"|"data.updated",   // absent = status.updated (a retry of a delivery created before the field existed); ProofAge\Sdk\Enums\WebhookEvent
  "status": string,                            // on data.updated: the current status, unchanged
  "external_id": string|null,
  "external_metadata": object|null,
  "reason": string|null,                       // only on resubmission_requested / declined
  "timestamp": string (ISO8601),
  "document": { "type": string|null, "issuing_country": string|null, "issuing_subdivision": string|null, "fields": {...} },   // the array shape of document() without media; absent on a body sent before this existed and on a subscription delivery without include_document_data
  "changed_fields"?: string[],                 // only on data.updated: names of what the correction changed (document.fields keys, or type, issuing_country, issuing_subdivision), no values
  "duplicate_detected"?: true,                 // present only when a duplicate was found
  "duplicate_count"?: int,                     // with duplicate_detected: every match found
  "duplicate_of"?: { "verification_id": string, "external_id": string|null },   // the first match
  "fingerprint_signals"?: { "ip_address"?, "ip_country_code"?, "ip_timezone"?, "device_timezone"?, ... },  // present only when signals were collected
  "manual_moderation"?: { "action": "approve"|"decline", "reason": string, "source": string,
                          "performed_by"?: string, "source_status"?: string|null, "source_reason"?: string|null }   // performed_by is absent on a subscription delivery without include_document_data
}
```

**Dispatch on `event` first** (`WebhookEvent::tryFrom($body['event'] ?? 'status.updated')`).
`status.updated` is every webhook you already know: the verification moved to `status`.
`data.updated` means someone on the tenant's team corrected document fields the reader got wrong
(console or MCP): `status` is the current one and a correction never changes it, `document` holds the
corrected values and `changed_fields` names what changed. It is not a decision: update the stored
document fields and leave the verification's status alone. A handler that ignores `event` sees what
looks like a resend of the same status, which it must tolerate anyway (de-duplicate on
`X-ProofAge-Webhook-Delivery-Id`, make applying a status idempotent). `tryFrom()` returns null for an
event added later: ignore it with a 2xx.

`document` is on every decision webhook, whatever the status: the same object `document()` returns
(`type`, `issuing_country`, `issuing_subdivision`, `fields`; see its array shape in `src/Resources/VerificationResource.php`),
without `media` and `meta`. KYC workspaces receive eleven `fields`; age workspaces receive
`first_name`, `last_name`, `date_of_birth` and `document_number` only, the other seven keys being
absent. `null` means not read, not printed, or no document read at all (an age estimate without an
ID, a test workspace, a wallet check). A resend and a manual retry carry the document as it is now,
an automatic retry the body as first sent; after erasure only `type` and `issuing_country` remain.
The SDK has no webhook DTO: decode the body yourself after `WebhookVerifier` has verified the raw
bytes, treat `document` as optional (a body sent before it existed lacks it, and so does a subscription delivery without `include_document_data`), and do not log the body.

## Keeping this in sync

This contract is drift-tested against `resources/openapi.json` via `tests/ApiContractTest.php`,
so it stays aligned with the API. Maintainers refreshing it after an API change: run
`composer run sync-spec` (copies the published spec from `https://docs.proofage.xyz/openapi.json`
into `resources/`), then make `tests/ApiContractTest.php` pass by updating
`tests/Support/ApiContractMap.php`, the `@param`/`@return` shapes in `src/Resources/`, and this
file together. The checklist for carrying an API change to every client is
`.ai/guidelines/api-changes.md` in the ProofAge app repo.
