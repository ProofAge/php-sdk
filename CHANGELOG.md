# Changelog

## 0.1.0 - 2026-09-02

First release: the ProofAge client extracted from `proofage/laravel-client` 0.6.0 into a
framework-neutral package. Runtime dependencies are `php ^8.1`, `ext-curl`, `ext-json` and
`psr/http-message`.

### Added

- `ProofAge\Sdk\Client` with the resource surface of the Laravel client unchanged:
  `workspace()->get()`, `getConsent()`, `verifications($id)->create()`, `find()`, `get()`,
  `acceptConsent()`, `uploadMedia()`, `submit()`, `document()`, `downloadMedia()`,
  `downloadMediaTo()`, `estimation()`, `blockFace()`; same config keys and defaults, same
  validation messages, same `\InvalidArgumentException('Verification ID is required')` guards.
- `ProofAge\Sdk\Signing\Signer`: the single implementation of both HMAC canonical forms, pinned by
  the golden vectors in `resources/hmac-vectors.json` (shipped in the dist for the server's tests).
- `ProofAge\Sdk\Http\HttpClient` transport interface with three implementations: the default
  `Curl\CurlHttpClient`, `Psr18\Psr18HttpClient` for an existing PSR-18 client, and
  `Testing\FakeHttpClient` for tests without network.
- Middleware pipeline (`pushMiddleware()` / `removeMiddleware()`): Retry outermost, user middleware
  in push order, Sign innermost. Middleware mutates the request before signing and never sees the
  auth headers.
- Events `onRequest()`, `onResponse()`, `onError()` with redacted views (API key and signature
  masked, bodies reduced to sizes and hashes, no response body) and an explicit `raw()`.
- `ProofAge\Sdk\Http\RetryPolicy` carrying both existing policies: interactive (transport failure,
  429, or 5xx; constant delay) and download (transport failure only).
- `ProofAge\Sdk\Exceptions\TransportException` for failures below HTTP, replacing the
  `Illuminate\Http\Client\ConnectionException` that used to escape; `ExceptionInterface` marker;
  `ExceptionFactory` seam so a host framework can have its own subclasses thrown.
- `ProofAge\Sdk\Webhooks\WebhookVerifier`: the framework-free header check sequence
  (`MISSING_SIGNATURE`, `MISSING_TIMESTAMP`, `MISSING_AUTH_CLIENT`, `INVALID_AUTH_CLIENT`,
  `TIMESTAMP_TOO_OLD`, `INVALID_SIGNATURE`) over the moved `WebhookSignatureVerifier`;
  `WebhookVerificationException::toArray()`.
- `ProofAge\Sdk\Stream\ResourceStream`, a PSR-7 stream over `php://temp` or a file, compatible with
  `psr/http-message` 1.x and 2.x.
- Enums `VerificationStatus`, `BlockFaceReasonCode`, `WebhookReason` under `ProofAge\Sdk\Enums`.
- `resources/openapi.json`, `tests/ApiContractTest.php`, `AGENTS.md`, `scripts/sync-spec.php` and
  `scripts/check-release.php`, moved from the Laravel package. The SDK is the source of truth for
  endpoint paths and request shapes.

### Changed from the Laravel client's behaviour

- A query string on an endpoint is now part of the signed path, normalized exactly as the server
  normalizes it (sorted keys, RFC 3986), and the same normalized string is sent in the URL. No
  current endpoint takes query parameters.
- A multipart file path that does not exist throws `\InvalidArgumentException("File not found: …")`
  instead of being silently dropped from the request and the signature.
- A request body `json_encode` cannot produce throws `ProofAgeException('Request body is not
  JSON-encodable')` with the `JsonException` as previous, instead of sending `false`.
- Multipart file contents are read once, at construction of the request, so the hash signed is the
  hash of the bytes sent even if the file changes on disk in between.
- `makeRequest()` and `makeStreamedRequest()` return `ProofAge\Sdk\Http\Response`, which mirrors
  the framework-free surface of Illuminate's response (`status()`, `body()`, `json()`, `header()`,
  `headers()`, `successful()`, `failed()`, `ok()`).
- With the bundled cURL transport a media download is received fully into `php://temp` (spilling
  to disk past 2 MB) before `downloadMedia()` returns; it is never held as a PHP string.
