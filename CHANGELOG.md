# Changelog

## 0.1.3 - 2026-09-03

### Fixed

- The API key and the webhook payload no longer reach an exception's stack trace.
  `WebhookVerifier::verify()` and `verifyHeaders()` took the caller's `X-Auth-Client`
  value and the raw body as ordinary arguments, so PHP recorded them in the trace
  frame and `print_r($e)` in a log printed both; `WebhookSignatureVerifier::verify()`
  and `generateSignature()` did the same with the payload. Those parameters are now
  marked `#[\SensitiveParameter]`.
  Note that the attribute takes effect from PHP 8.2. On 8.1, which this package still
  supports, it is ignored and `zend.exception_ignore_args=1` remains the only
  protection — see the README.

## 0.1.2 - 2026-09-03

Fixes from an adversarial review of 0.1.1. Each was reproduced by running code before it was fixed.

### Fixed

- The secret key and request bodies no longer reach `print_r()` / `var_dump()`: `Client`, `Signer`,
  `SignMiddleware`, `Request`, `FilePart`, `RawBody` and both webhook verifiers implement
  `__debugInfo()` (secret redacted, API key and signature masked as the events mask them, bodies as
  size plus sha256), the pipeline's `$next` closures are no longer bound to an object holding the
  signer, and `#[\SensitiveParameter]` keeps the config out of a constructor failure's trace on
  PHP 8.2+. `var_export()`, reflection and Symfony's VarDumper are not covered; the README says so.
- `Response::json()` takes `(?string $key = null, mixed $default = null)` with a dot path, as
  Illuminate's does; `json('error.code')` used to return the whole document because PHP ignores
  extra arguments.
- `downloadMediaTo()` (and `makeStreamedRequest()` with a sink) writes to a temporary sibling file
  and renames it into place after a 2xx; a 404 or a transport failure leaves nothing at the
  destination and a previous file there untouched. The exception still carries the error body.
- `FakeHttpClient` gives every hit its own body stream; a second `downloadMedia()` against one fake
  route used to return an empty string.
- `WebhookVerifier` compares `X-Auth-Client` with `hash_equals()`.
- `RetryMiddleware` does not retry a transport failure the transport marks as not retryable:
  `CurlHttpClient` marks `CURLE_URL_MALFORMAT` and `CURLE_UNSUPPORTED_PROTOCOL`, which are raised by
  the URL alone. `TransportException::isRetryable()` exposes the flag (fourth constructor argument,
  default `true`).
- `README.md` describes the interactive retry policy as implemented (any non-2xx that is not a
  4xx, plus 429 and transport failures), and the golden-vector statements say the server's test
  suite *can* execute the fixture rather than that it does.

### Changed

- `Response::headers()` keeps header names as the server sent them (`Content-Type`, not
  `content-type`); two spellings of one name merge under the first seen. `header()` stays
  case-insensitive. Code that indexed `headers()` with a lower-cased literal must use `header()` or
  the server's spelling. `ResponseEvent::headers()` follows.
- `Client::makeRequest()` / `makeStreamedRequest()` percent-encode each endpoint path segment once,
  so the signed path is the sent path, and throw `\InvalidArgumentException` for an endpoint with
  `.` or `..` segments, a `#`, whitespace or control characters. Those endpoints used to produce a
  401 "HMAC signature is invalid". Pass raw segments; a pre-encoded segment is encoded again.
- `Request::$sink` seen by middleware, events and `FakeHttpClient::sent()` during a download to a
  path is the temporary `{path}.{random}.part` the transport writes to, not the final path.
- `timeout`, `retry_attempts`, `retry_delay` and `download_retry_attempts` must be integers
  (integer-valued strings are accepted); a float such as `timeout => 0.5`, which used to become
  `0` and mean "no timeout" to cURL, now throws `ProofAgeException` at construction. Zero attempts
  still clamp to one.
- `Request::withHeader()` (and the constructor) throw `\InvalidArgumentException` for a header
  value containing CR, LF or NUL, or a name that is not an HTTP token.
- `MultipartBody` (so `uploadMedia()` and `makeRequest()` with files) throws
  `\InvalidArgumentException` for a top-level field name that is not a valid PHP variable name, or a
  file field name used twice; PHP would register those under a different name on the server and
  the signature would not match.

### Added

- `Client::__construct(array $config, ?HttpClient $transport = null, ?ExceptionFactory $exceptions = null, ?callable $sleep = null)`:
  `$sleep` receives microseconds and replaces the `usleep()` between retry attempts, so a host
  framework's time fake covers it (Laravel: `Sleep::usleep(...)`).

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
