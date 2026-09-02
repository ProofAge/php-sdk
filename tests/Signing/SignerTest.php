<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Tests\Signing;

use PHPUnit\Framework\TestCase;
use ProofAge\Sdk\Http\Body\FilePart;
use ProofAge\Sdk\Http\Body\MultipartBody;
use ProofAge\Sdk\Http\Body\RawBody;
use ProofAge\Sdk\Http\Request;
use ProofAge\Sdk\Http\RetryPolicy;
use ProofAge\Sdk\Signing\Signer;

class SignerTest extends TestCase
{
    private string $secret = 'test-secret-key';

    public function test_sign_raw_is_hmac_sha256_over_method_path_and_raw_body(): void
    {
        // Ported from proofage-laravel-client tests/ProofAgeClientTest.php:115-134,
        // which reached generateHmacSignature() through reflection.
        $rawBody = json_encode(['callback_url' => 'https://example.com/webhook']);

        $signature = (new Signer($this->secret))->signRaw('POST', '/v1/verifications', (string) $rawBody);

        $this->assertSame(64, strlen($signature));
        $this->assertSame(hash_hmac('sha256', 'POST/v1/verifications'.$rawBody, $this->secret), $signature);
    }

    public function test_sign_raw_defaults_to_an_empty_body(): void
    {
        $signer = new Signer($this->secret);

        $this->assertSame($signer->signRaw('GET', '/v1/workspace', ''), $signer->signRaw('GET', '/v1/workspace'));
    }

    public function test_method_is_upper_cased_in_both_forms(): void
    {
        $this->assertSame('POST/v1/x{}', Signer::canonicalRaw('post', '/v1/x', '{}'));
        $this->assertSame("POST/v1/x\n\n", Signer::canonicalMultipart('post', '/v1/x', [], []));
    }

    public function test_canonicalize_fields_ksorts_recursively(): void
    {
        $this->assertSame(
            ['a' => ['x' => 1, 'y' => ['m' => 1, 'n' => 2]], 'b' => 2],
            Signer::canonicalizeFields(['b' => 2, 'a' => ['y' => ['n' => 2, 'm' => 1], 'x' => 1]]),
        );
    }

    public function test_canonical_multipart_sorts_hashes_and_joins_them_with_commas(): void
    {
        $this->assertSame(
            "POST/v1/m\ntype=selfie\naaa,bbb,ccc",
            Signer::canonicalMultipart('POST', '/v1/m', ['type' => 'selfie'], ['ccc', 'aaa', 'bbb']),
        );
    }

    public function test_canonical_multipart_encodes_fields_as_an_rfc3986_query(): void
    {
        $this->assertSame(
            "POST/v1/m\na=x%20y&b%5Bc%5D=1\n",
            Signer::canonicalMultipart('POST', '/v1/m', ['b' => ['c' => 1], 'a' => 'x y'], []),
        );
    }

    public function test_sign_multipart_is_hmac_over_the_canonical_string(): void
    {
        $canonical = Signer::canonicalMultipart('POST', '/v1/m', ['type' => 'selfie'], ['abc']);

        $this->assertSame(
            hash_hmac('sha256', $canonical, $this->secret),
            (new Signer($this->secret))->signMultipart('POST', '/v1/m', ['type' => 'selfie'], ['abc']),
        );
    }

    public function test_normalize_query_string_sorts_keys_and_encodes_rfc3986(): void
    {
        $this->assertSame('a=1&b=2&c=x%20y', Signer::normalizeQueryString('c=x+y&b=2&a=1'));
        $this->assertSame('a=1&b=2&c=x%20y', Signer::normalizeQueryString('c=x%20y&b=2&a=1'));
    }

    public function test_normalize_query_string_is_empty_for_an_empty_input(): void
    {
        $this->assertSame('', Signer::normalizeQueryString(''));
    }

    public function test_normalize_query_string_keeps_a_key_without_a_value(): void
    {
        $this->assertSame('a=1&flag=', Signer::normalizeQueryString('flag&a=1'));
    }

    public function test_normalize_query_string_preserves_dots_and_spaces_in_keys_like_symfony_does(): void
    {
        // parse_str() alone would turn `a.b` into `a_b`; Symfony's HeaderUtils::parseQuery()
        // protects the key, and the server signs that result, so the SDK must too.
        $this->assertSame('a.b=1&c%20d=2', Signer::normalizeQueryString('c%20d=2&a.b=1'));
    }

    public function test_normalize_query_string_sorts_only_the_top_level(): void
    {
        // Symfony\Component\HttpFoundation\Request::normalizeQueryString() ksorts once, not
        // recursively; nested keys keep their order. The server signs exactly that.
        $this->assertSame(
            'a%5By%5D=1&a%5Bx%5D=2&b=0',
            Signer::normalizeQueryString('b=0&a[y]=1&a[x]=2'),
        );
    }

    private function request(RawBody|MultipartBody|null $body, string $method = 'POST'): Request
    {
        return new Request($method, 'https://api.test.com/v1/m', '/v1/m', [], $body, RetryPolicy::interactive(), 30);
    }

    public function test_sign_uses_the_raw_form_for_a_raw_body(): void
    {
        $signer = new Signer($this->secret);

        $this->assertSame($signer->signRaw('POST', '/v1/m', '{"a":1}'), $signer->sign($this->request(new RawBody('{"a":1}'))));
    }

    public function test_sign_uses_the_raw_form_with_an_empty_body_when_there_is_no_body(): void
    {
        $signer = new Signer($this->secret);

        $this->assertSame($signer->signRaw('GET', '/v1/m', ''), $signer->sign($this->request(null, 'GET')));
    }

    public function test_sign_uses_the_multipart_form_for_a_multipart_body(): void
    {
        $signer = new Signer($this->secret);
        $part = new FilePart('file', 'a.jpg', 'bytes');
        $body = new MultipartBody(['type' => 'selfie'], [$part]);

        $this->assertSame(
            $signer->signMultipart('POST', '/v1/m', ['type' => 'selfie'], [$part->sha256()]),
            $signer->sign($this->request($body)),
        );
    }

    public function test_sign_covers_the_path_including_a_query_but_not_the_url(): void
    {
        $signer = new Signer($this->secret);
        $request = $this->request(null, 'GET')->withPath('/v1/m?a=1')->withUrl('https://proxy.test/anything');

        $this->assertSame($signer->signRaw('GET', '/v1/m?a=1'), $signer->sign($request));
    }
}
