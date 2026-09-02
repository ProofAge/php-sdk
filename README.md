# ProofAge PHP SDK

Framework-neutral PHP client for the [ProofAge](https://proofage.xyz) age and identity verification API:
HMAC request signing, the resource methods, streaming media downloads, and inbound webhook
verification. Runtime dependencies are `php ^8.1`, `ext-curl`, `ext-json` and the
`psr/http-message` interfaces — nothing else, so it installs cleanly next to whatever your
project already uses.

On Laravel, use [`proofage/laravel-client`](https://github.com/ProofAge/laravel-client) instead:
it wraps this SDK with a service provider, a facade, the webhook middleware and the
`proofage:verify-setup` command.

## Install

```bash
composer require proofage/php-sdk
```

## First request

```php
use ProofAge\Sdk\Client;

$client = new Client([
    'api_key' => getenv('PROOFAGE_API_KEY'),
    'secret_key' => getenv('PROOFAGE_SECRET_KEY'),
    'base_url' => 'https://api.proofage.xyz',
]);

$workspace = $client->workspace()->get();
```

Every request is signed with `X-API-Key` and `X-HMAC-Signature`; you never touch either.

### Configuration

| Key | Default | |
|---|---|---|
| `api_key` | required | Workspace API key |
| `secret_key` | required | Workspace secret key, used only to sign |
| `base_url` | required | `https://api.proofage.xyz`; must have no path component |
| `version` | `v1` | API version segment |
| `timeout` | `30` | Seconds per attempt |
| `retry_attempts` | `3` | Attempts for interactive requests; a transport failure, a 429 or a 5xx earns another one |
| `retry_delay` | `1000` | Milliseconds between attempts, constant |
| `download_retry_attempts` | `1` | Attempts for media downloads; only a transport failure is retried, never an HTTP status |

## Resources

```php
$verification = $client->verifications()->create([
    'callback_url' => 'https://example.com/proofage/webhook',
    'external_id' => 'user-42',
]);

$v = $client->verifications($verification['id']);

$v->get();
$v->acceptConsent(['consent_version_id' => $consent['id'], 'text_sha256' => $consent['text_sha256']]);
$v->uploadMedia(['type' => 'document', 'side' => 'front', 'document' => 'passport', 'file' => '/tmp/front.jpg']);
$v->uploadMedia(['type' => 'selfie', 'file' => new \SplFileInfo('/tmp/selfie.jpg')]);
$v->submit();
$v->document();
$v->estimation();
$v->blockFace(['reason_code' => \ProofAge\Sdk\Enums\BlockFaceReasonCode::UNDERAGE->value]);

$client->workspace()->get();
$client->workspace()->getConsent();
```

Methods return the decoded JSON as `array|null`. Every request and response shape is documented
in [`AGENTS.md`](AGENTS.md) and in the `@param`/`@return` PHPDoc on `src/Resources/`.

`uploadMedia()` accepts a path, any `\SplFileInfo` (Symfony's and Laravel's `UploadedFile`
included) or a `ProofAge\Sdk\Http\Body\FilePart`. A path that does not exist throws
`\InvalidArgumentException` before anything is sent.

### Media downloads

```php
$stream = $v->downloadMedia($mediaId);          // Psr\Http\Message\StreamInterface
$path = $v->downloadMediaTo($mediaId, '/var/media/front.jpg');
```

With the bundled cURL transport the body is received into `php://temp`, which spills to disk past
2 MB, and is never held as a PHP string. Downloads never retry an HTTP status (429 included): they
usually run from a queue whose own backoff owns the wait. Raise `download_retry_attempts` to retry
connection failures only.

## Errors

```php
use ProofAge\Sdk\Exceptions\AuthenticationException;   // 401
use ProofAge\Sdk\Exceptions\ValidationException;       // 422, getErrors()
use ProofAge\Sdk\Exceptions\TransportException;        // connection refused, DNS, TLS, timeout
use ProofAge\Sdk\Exceptions\ProofAgeException;         // every other non-2xx, and the base class
use ProofAge\Sdk\Exceptions\ExceptionInterface;        // marker: catch the whole family

try {
    $client->verifications()->create($data);
} catch (ValidationException $e) {
    $errors = $e->getErrors();
} catch (TransportException $e) {
    // no response: $e->getResponse() is null, $e->getCode() is the cURL errno
} catch (ProofAgeException $e) {
    $e->getCode();       // HTTP status
    $e->getErrorCode();  // error.code from the body, e.g. MEDIA_NOT_FOUND
    $e->getResponse();   // ProofAge\Sdk\Http\Response
}
```

Missing verification IDs and missing files throw `\InvalidArgumentException`.

## Webhooks

ProofAge signs every delivery with `X-Auth-Client`, `X-Timestamp` and `X-HMAC-Signature`
(HMAC-SHA256 of `{timestamp}.{rawBody}`).

```php
use ProofAge\Sdk\Webhooks\WebhookVerifier;
use ProofAge\Sdk\Exceptions\WebhookVerificationException;

$verifier = new WebhookVerifier(getenv('PROOFAGE_API_KEY'), getenv('PROOFAGE_SECRET_KEY'));

try {
    $verifier->verifyHeaders(getallheaders(), file_get_contents('php://input'));
} catch (WebhookVerificationException $e) {
    http_response_code($e->statusCode);
    echo json_encode($e->toArray());   // {"error": {"code": "INVALID_SIGNATURE", "message": "..."}}
    exit;
}
```

Codes, in the order they are checked: `MISSING_SIGNATURE`, `MISSING_TIMESTAMP`,
`MISSING_AUTH_CLIENT`, `INVALID_AUTH_CLIENT`, `TIMESTAMP_TOO_OLD`, `INVALID_SIGNATURE`. The
timestamp tolerance defaults to 300 seconds (third constructor argument).

## Middleware

A middleware is `callable(Request $request, callable $next): Response`. It runs once per HTTP
attempt and **before signing**, so whatever it changes is what gets signed — a middleware can add
a header or rewrite the body and the signature stays valid. It never sees `X-API-Key` or
`X-HMAC-Signature`; those are added below it. The first middleware pushed is the outermost.

```php
use ProofAge\Sdk\Http\Request;
use ProofAge\Sdk\Http\Response;

$client->pushMiddleware(function (Request $request, callable $next): Response {
    return $next($request->withHeader('X-Request-Id', bin2hex(random_bytes(8))));
}, 'request-id');

// Once per logical call rather than per attempt:
$client->pushMiddleware(function (Request $request, callable $next): Response {
    if ($request->attempt === 1) {
        $quota->consume();
    }

    return $next($request);
});

$client->removeMiddleware('request-id');
```

A middleware that returns a `Response` without calling `$next` short-circuits: nothing is signed,
no event fires, nothing is sent.

## Events

Events observe the signed request going down and the response or transport failure coming back,
once per attempt.

```php
use ProofAge\Sdk\Events\{RequestEvent, ResponseEvent, ErrorEvent};

$client->onRequest(fn (RequestEvent $e) => $log->info('proofage.request', [
    'method' => $e->method(),
    'url' => $e->url(),
    'attempt' => $e->attempt(),
    'headers' => $e->headers(),   // ['X-API-Key' => '****7f2a', 'X-HMAC-Signature' => '4c6daa63...', ...]
    'body' => $e->body(),         // ['kind' => 'multipart', 'fields' => [...], 'files' => [['name' => 'file', 'filename' => 'front.jpg', 'bytes' => 183422, 'sha256' => '...']]]
]));

$client->onResponse(fn (ResponseEvent $e) => $metrics->timing('proofage.request_ms', $e->durationMs(), [
    'status' => $e->status(),
    'attempt' => $e->attempt(),
]));

$client->onError(fn (ErrorEvent $e) => $log->warning('proofage.transport', [
    'attempt' => $e->attempt(),
    'error' => $e->exception()->getMessage(),
]));
```

An HTTP error status is a response and arrives through `onResponse`; `onError` fires only for
transport failures. A listener that throws aborts the request.

### What the events redact, and what `raw()` exposes

Events are views built for logging. `RequestEvent::headers()` masks `X-API-Key` to its last four
characters and `X-HMAC-Signature` to its first eight; `RequestEvent::body()` reduces a JSON body
to its byte count and sha256 and each file to name, filename, size and sha256 (scalar form fields
such as `type` and `side` are shown verbatim); `ResponseEvent` has no body accessor at all.

`raw()` on any event returns the underlying `Request` or `Response` with everything in it: the
API key, a signature that — since API signing carries no timestamp or nonce — replays the request
verbatim, document photos and selfies in multipart bodies, and response bodies carrying names,
dates of birth and document numbers. Call it deliberately, and do not log what it returns.

## Transports

The default transport is a bundled cURL client (`ProofAge\Sdk\Http\Curl\CurlHttpClient`): one
handle per request, TLS verification on, no redirects, 10 s connect timeout.

### PSR-18

If you already have a PSR-18 client and PSR-17 factories:

```php
use ProofAge\Sdk\Http\Psr18\Psr18HttpClient;

$factory = new \GuzzleHttp\Psr7\HttpFactory;
$transport = new Psr18HttpClient(new \GuzzleHttp\Client(['timeout' => 30]), $factory, $factory);

$client = new Client($config, $transport);
```

PSR-18 has no per-request timeout, so `timeout` from the config is not applied there; configure
it on your client. Any `Psr\Http\Client\ClientExceptionInterface` surfaces as
`TransportException` with the original as `getPrevious()`.

### Your own

Implement `ProofAge\Sdk\Http\HttpClient` — one method, `send(Request): Response` — and pass it as
the second constructor argument. A transport sends exactly what it is given and never retries or
throws on an HTTP status; the SDK owns both.

## Testing your integration

`ProofAge\Sdk\Testing\FakeHttpClient` ships in the package and needs no network:

```php
use ProofAge\Sdk\Testing\FakeHttpClient;

$fake = new FakeHttpClient([
    'api.proofage.xyz/v1/workspace' => FakeHttpClient::json(['id' => 'ws_1', 'name' => 'Acme']),
    'api.proofage.xyz/v1/verifications/*' => [               // a sequence
        FakeHttpClient::json(['error' => ['code' => 'RATE_LIMIT']], 429),
        FakeHttpClient::json(['id' => 'ver_1', 'status' => 'created']),
    ],
    '*' => FakeHttpClient::failedConnection(),
]);

$client = new Client($config, $fake);

$client->workspace()->get();

$fake->assertSent(fn ($request) => $request->method === 'GET' && str_ends_with($request->url, '/v1/workspace'));
$fake->assertSentCount(1);
```

Patterns use `*` wildcards and are tried in order. `sent()` returns the requests as the transport
received them — signed, one per attempt — so you can assert `X-HMAC-Signature` and
`$request->body->bytes` directly. An unmatched URL throws `\LogicException`.

## Contract

`resources/openapi.json` is the bundled API spec and `resources/hmac-vectors.json` the golden
signature vectors both this SDK and the ProofAge server execute. See [`AGENTS.md`](AGENTS.md).

## License

MIT. See [`LICENSE.md`](LICENSE.md).
