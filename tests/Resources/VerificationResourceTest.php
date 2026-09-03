<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Tests\Resources;

use PHPUnit\Framework\TestCase;
use ProofAge\Sdk\Client;
use ProofAge\Sdk\Exceptions\ProofAgeException;
use ProofAge\Sdk\Exceptions\TransportException;
use ProofAge\Sdk\Http\Body\FilePart;
use ProofAge\Sdk\Http\Body\MultipartBody;
use ProofAge\Sdk\Http\Request;
use ProofAge\Sdk\Resources\VerificationResource;
use ProofAge\Sdk\Testing\FakeHttpClient;
use Psr\Http\Message\StreamInterface;

/**
 * Ported from proofage-laravel-client tests/VerificationResourceTest.php with Http::fake()
 * replaced by FakeHttpClient; assertions unchanged. The retry cases (:281-343) live in
 * ClientTest and RetryMiddlewareTest.
 */
class VerificationResourceTest extends TestCase
{
    private FakeHttpClient $fake;

    private string $imagePath;

    protected function setUp(): void
    {
        $this->imagePath = sys_get_temp_dir().'/proofage-resource-'.uniqid().'.jpg';
        file_put_contents($this->imagePath, 'not-really-a-jpeg');
    }

    protected function tearDown(): void
    {
        @unlink($this->imagePath);
    }

    /** @param array<string, mixed> $fakeResponses */
    private function makeFakedClient(array $fakeResponses): Client
    {
        $this->fake = new FakeHttpClient($fakeResponses);

        return new Client([
            'api_key' => 'test-api-key',
            'secret_key' => 'test-secret-key',
            'base_url' => 'https://api.test.com',
            'version' => 'v1',
        ], $this->fake);
    }

    public function test_verifications_returns_a_resource_with_or_without_an_id(): void
    {
        $client = $this->makeFakedClient([]);

        $this->assertInstanceOf(VerificationResource::class, $client->verifications());
        $this->assertInstanceOf(VerificationResource::class, $client->verifications('ver_1'));
        $this->assertSame(0, VerificationResource::GENDER_FEMALE);
        $this->assertSame(1, VerificationResource::GENDER_MALE);
    }

    public function test_create_sends_post_with_data(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/v1/verifications' => FakeHttpClient::json([
                'id' => 'ver_new',
                'status' => 'pending',
            ]),
        ]);

        $result = $client->verifications()->create([
            'callback_url' => 'https://example.com/callback',
            'metadata' => ['user_id' => 42],
        ]);

        $this->assertEquals('ver_new', $result['id']);
        $this->assertEquals('pending', $result['status']);

        $this->fake->assertSent(function (Request $request) {
            return $request->method === 'POST'
                && str_contains($request->url, '/v1/verifications')
                && $request->header('X-HMAC-Signature') !== null;
        });
    }

    public function test_find_sends_get_to_correct_endpoint(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/v1/verifications/ver_abc' => FakeHttpClient::json([
                'id' => 'ver_abc',
                'status' => 'approved',
            ]),
        ]);

        $result = $client->verifications()->find('ver_abc');

        $this->assertEquals('ver_abc', $result['id']);
        $this->assertEquals('approved', $result['status']);

        $this->fake->assertSent(function (Request $request) {
            return $request->method === 'GET'
                && str_contains($request->url, '/v1/verifications/ver_abc');
        });
    }

    public function test_get_throws_when_no_id_set(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/*' => FakeHttpClient::json([]),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Verification ID is required');

        $client->verifications()->get();
    }

    public function test_get_fetches_by_constructor_id(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/v1/verifications/ver_xyz' => FakeHttpClient::json([
                'id' => 'ver_xyz',
                'status' => 'pending',
            ]),
        ]);

        $result = $client->verifications('ver_xyz')->get();

        $this->assertEquals('ver_xyz', $result['id']);
    }

    public function test_accept_consent_sends_post(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/v1/verifications/ver_123/consent' => FakeHttpClient::json(['accepted' => true]),
        ]);

        $result = $client->verifications('ver_123')->acceptConsent([
            'consent_version_id' => 1,
            'text_sha256' => 'hash',
        ]);

        $this->assertTrue($result['accepted']);

        $this->fake->assertSent(function (Request $request) {
            return $request->method === 'POST'
                && str_contains($request->url, '/v1/verifications/ver_123/consent');
        });
    }

    public function test_accept_consent_throws_when_no_id(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/*' => FakeHttpClient::json([]),
        ]);

        $this->expectException(\InvalidArgumentException::class);

        $client->verifications()->acceptConsent(['consent_version_id' => 1]);
    }

    public function test_upload_media_sends_multipart_request(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/*' => FakeHttpClient::json(['id' => 'media_456']),
        ]);

        $result = $client->verifications('ver_123')->uploadMedia([
            'type' => 'document_front',
            'file' => $this->imagePath,
        ]);

        $this->assertEquals('media_456', $result['id']);

        $this->fake->assertSent(function (Request $request) {
            return str_contains($request->url, '/v1/verifications/ver_123/media')
                && $request->header('X-HMAC-Signature') !== null;
        });

        $sent = $this->fake->sent()[0];
        $this->assertInstanceOf(MultipartBody::class, $sent->body);
        $this->assertSame(['type' => 'document_front'], $sent->body->fields, '`file` is extracted from the data into the multipart files.');
        $this->assertSame('file', $sent->body->files[0]->name);
        $this->assertSame(basename($this->imagePath), $sent->body->files[0]->filename);
    }

    public function test_upload_media_accepts_an_uploaded_file_shaped_object(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/*' => FakeHttpClient::json(['message' => 'ok']),
        ]);
        $uploaded = new class($this->imagePath) extends \SplFileInfo
        {
            public function getClientOriginalName(): string
            {
                return 'selfie.jpg';
            }
        };

        $client->verifications('ver_123')->uploadMedia(['type' => 'selfie', 'file' => $uploaded]);

        $sent = $this->fake->sent()[0];
        $this->assertInstanceOf(MultipartBody::class, $sent->body);
        $this->assertSame('selfie.jpg', $sent->body->files[0]->filename);
        $this->assertSame('not-really-a-jpeg', $sent->body->files[0]->contents);
    }

    public function test_upload_media_accepts_a_file_part(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/*' => FakeHttpClient::json(['message' => 'ok']),
        ]);

        $client->verifications('ver_123')->uploadMedia(['type' => 'selfie', 'file' => new FilePart('file', 'inline.jpg', 'bytes')]);

        $sent = $this->fake->sent()[0];
        $this->assertInstanceOf(MultipartBody::class, $sent->body);
        $this->assertSame('inline.jpg', $sent->body->files[0]->filename);
    }

    public function test_upload_media_throws_when_no_id(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/*' => FakeHttpClient::json([]),
        ]);

        $this->expectException(\InvalidArgumentException::class);

        $client->verifications()->uploadMedia(['type' => 'selfie']);
    }

    public function test_upload_media_without_a_file_sends_json(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/*' => FakeHttpClient::json(['message' => 'ok']),
        ]);

        $client->verifications('ver_123')->uploadMedia(['type' => 'selfie']);

        $this->assertNotInstanceOf(MultipartBody::class, $this->fake->sent()[0]->body);
    }

    public function test_submit_sends_post(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/v1/verifications/ver_123/submit' => FakeHttpClient::json([
                'id' => 'ver_123',
                'status' => 'processing',
            ]),
        ]);

        $result = $client->verifications('ver_123')->submit();

        $this->assertEquals('processing', $result['status']);

        $this->fake->assertSent(function (Request $request) {
            return $request->method === 'POST'
                && str_contains($request->url, '/v1/verifications/ver_123/submit');
        });
    }

    public function test_submit_throws_when_no_id(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/*' => FakeHttpClient::json([]),
        ]);

        $this->expectException(\InvalidArgumentException::class);

        $client->verifications()->submit();
    }

    public function test_document_sends_get_to_document_endpoint(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/v1/verifications/ver_123/document' => FakeHttpClient::json([
                'document' => [
                    'fields' => [
                        'first_name' => 'John',
                        'last_name' => 'Doe',
                        'date_of_birth' => '1990-01-15',
                        'document_number' => 'AB123456',
                    ],
                ],
                'media' => [
                    [
                        'id' => 'media_selfie',
                        'type' => 'selfie',
                        'url' => 'https://api.test.com/v1/verifications/ver_123/media/media_selfie',
                    ],
                ],
                'meta' => [
                    'attempt_id' => 'attempt_123',
                ],
            ]),
        ]);

        $result = $client->verifications('ver_123')->document();

        $this->assertSame('John', $result['document']['fields']['first_name']);
        $this->assertSame('AB123456', $result['document']['fields']['document_number']);
        $this->assertSame('attempt_123', $result['meta']['attempt_id']);

        $this->fake->assertSent(function (Request $request) {
            return $request->method === 'GET'
                && str_contains($request->url, '/v1/verifications/ver_123/document')
                && $request->header('X-HMAC-Signature') !== null;
        });
    }

    public function test_download_media_streams_bytes_from_the_media_endpoint(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/v1/verifications/ver_1/media/med_1' => FakeHttpClient::raw(
                'binary-image-bytes',
                200,
                ['Content-Type' => 'image/jpeg'],
            ),
        ]);

        $body = $client->verifications('ver_1')->downloadMedia('med_1');

        $this->assertInstanceOf(StreamInterface::class, $body);
        $this->assertSame('binary-image-bytes', (string) $body);

        $this->fake->assertSent(function (Request $request) {
            return $request->method === 'GET'
                && str_contains($request->url, '/v1/verifications/ver_1/media/med_1')
                && $request->header('X-API-Key') !== null
                && $request->header('X-HMAC-Signature') !== null
                && $request->stream === true;
        });
    }

    public function test_download_media_can_be_called_twice_against_the_same_fake_route(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/v1/verifications/ver_1/media/*' => FakeHttpClient::raw('binary-image-bytes'),
        ]);

        $first = $client->verifications('ver_1')->downloadMedia('med_1')->getContents();
        $second = $client->verifications('ver_1')->downloadMedia('med_2')->getContents();

        $this->assertSame('binary-image-bytes', $first);
        $this->assertSame('binary-image-bytes', $second, 'A second download in a consumer test used to come back empty.');
    }

    public function test_download_media_signs_the_path_with_an_empty_body(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/v1/verifications/ver_1/media/med_1' => FakeHttpClient::raw('bytes'),
        ]);

        $client->verifications('ver_1')->downloadMedia('med_1');

        $expected = hash_hmac('sha256', 'GET/v1/verifications/ver_1/media/med_1', 'test-secret-key');

        $this->fake->assertSent(fn (Request $request) => $request->header('X-HMAC-Signature') === $expected);
    }

    public function test_download_media_to_writes_the_file_to_disk(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/v1/verifications/ver_1/media/med_1' => FakeHttpClient::raw('binary-image-bytes'),
        ]);

        $path = sys_get_temp_dir().'/proofage-media-'.uniqid().'.jpg';

        $returned = $client->verifications('ver_1')->downloadMediaTo('med_1', $path);

        $this->assertSame($path, $returned);
        $this->assertFileExists($path);
        $this->assertSame('binary-image-bytes', file_get_contents($path));

        unlink($path);
    }

    /**
     * On a 404 the destination used to hold the JSON error body under the media's
     * filename, and on a transport failure an empty file: a caller who did not check had
     * a corrupt "media" file on disk.
     */
    public function test_download_media_to_leaves_no_file_when_the_server_answers_an_error(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/v1/verifications/ver_1/media/med_1' => FakeHttpClient::json(['error' => ['code' => 'MEDIA_NOT_FOUND', 'message' => 'Media not found']], 404),
        ]);
        $path = sys_get_temp_dir().'/proofage-media-'.uniqid().'.jpg';

        try {
            $client->verifications('ver_1')->downloadMediaTo('med_1', $path);
            $this->fail('Expected a ProofAgeException.');
        } catch (ProofAgeException $e) {
            $this->assertSame(404, $e->getCode());
            $this->assertSame('MEDIA_NOT_FOUND', $e->getErrorCode(), 'The error body is still readable from the exception.');
            $this->assertSame('Media not found', $e->getMessage());
        }

        $this->assertFileDoesNotExist($path);
        $this->assertSame([], glob($path.'*'), 'No partial file is left next to the destination.');
    }

    public function test_download_media_to_leaves_no_file_when_the_transport_fails(): void
    {
        $client = $this->makeFakedClient(['*' => FakeHttpClient::failedConnection('Connection timed out')]);
        $path = sys_get_temp_dir().'/proofage-media-'.uniqid().'.jpg';

        try {
            $client->verifications('ver_1')->downloadMediaTo('med_1', $path);
            $this->fail('Expected a TransportException.');
        } catch (TransportException $e) {
            $this->assertSame('Connection timed out', $e->getMessage());
        }

        $this->assertFileDoesNotExist($path);
        $this->assertSame([], glob($path.'*'));
    }

    public function test_download_media_to_keeps_an_existing_file_intact_when_the_download_fails(): void
    {
        $client = $this->makeFakedClient(['*' => FakeHttpClient::json(['error' => ['code' => 'MEDIA_NOT_FOUND']], 404)]);
        $path = sys_get_temp_dir().'/proofage-media-'.uniqid().'.jpg';
        file_put_contents($path, 'previous-download');

        try {
            $client->verifications('ver_1')->downloadMediaTo('med_1', $path);
            $this->fail('Expected a ProofAgeException.');
        } catch (ProofAgeException) {
        }

        $this->assertSame('previous-download', file_get_contents($path));
        $this->assertSame([$path], glob($path.'*'));

        unlink($path);
    }

    public function test_download_media_to_replaces_an_existing_file_on_success(): void
    {
        $client = $this->makeFakedClient(['*' => FakeHttpClient::raw('fresh-bytes')]);
        $path = sys_get_temp_dir().'/proofage-media-'.uniqid().'.jpg';
        file_put_contents($path, 'stale-bytes');

        $client->verifications('ver_1')->downloadMediaTo('med_1', $path);

        $this->assertSame('fresh-bytes', file_get_contents($path));
        $this->assertSame([$path], glob($path.'*'));

        unlink($path);
    }

    public function test_download_media_throws_when_no_id(): void
    {
        $client = $this->makeFakedClient([]);

        $this->expectException(\InvalidArgumentException::class);

        $client->verifications()->downloadMedia('med_1');
    }

    public function test_download_media_to_throws_when_no_id(): void
    {
        $client = $this->makeFakedClient([]);

        $this->expectException(\InvalidArgumentException::class);

        $client->verifications()->downloadMediaTo('med_1', sys_get_temp_dir().'/never-written.jpg');
    }

    public function test_document_throws_when_no_id(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/*' => FakeHttpClient::json([]),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Verification ID is required');

        $client->verifications()->document();
    }

    public function test_estimation_sends_get_to_estimation_endpoint(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/v1/verifications/ver_123/estimation' => FakeHttpClient::json([
                'verification_id' => 'ver_123',
                'attempt_id' => 'attempt_123',
                'age_threshold' => [
                    'minimum' => 18,
                    'passed' => true,
                    'confidence' => 0.98,
                ],
                'gender' => [
                    'value' => 0,
                    'confidence' => 0.93,
                ],
            ]),
        ]);

        $result = $client->verifications('ver_123')->estimation();

        $this->assertSame('ver_123', $result['verification_id']);
        $this->assertSame(18, $result['age_threshold']['minimum']);
        $this->assertSame(VerificationResource::GENDER_FEMALE, $result['gender']['value']);

        $this->fake->assertSent(function (Request $request) {
            return $request->method === 'GET'
                && str_contains($request->url, '/v1/verifications/ver_123/estimation')
                && $request->header('X-HMAC-Signature') !== null;
        });
    }

    public function test_estimation_throws_when_no_id(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/*' => FakeHttpClient::json([]),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Verification ID is required');

        $client->verifications()->estimation();
    }

    public function test_block_face_sends_post(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/v1/verifications/ver_123/blocked-face' => FakeHttpClient::raw('', 204),
        ]);

        $result = $client->verifications('ver_123')->blockFace();

        $this->assertNull($result);

        $this->fake->assertSent(function (Request $request) {
            return $request->method === 'POST'
                && str_contains($request->url, '/v1/verifications/ver_123/blocked-face')
                && $request->header('X-HMAC-Signature') !== null
                && $request->body === null;
        });
    }

    public function test_block_face_sends_optional_body_data(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/v1/verifications/ver_123/blocked-face' => FakeHttpClient::raw('', 204),
        ]);

        $result = $client->verifications('ver_123')->blockFace([
            'reason' => 'text here',
        ]);

        $this->assertNull($result);

        $expectedBody = json_encode(['reason' => 'text here']);
        $expectedSignature = hash_hmac(
            'sha256',
            'POST/v1/verifications/ver_123/blocked-face'.$expectedBody,
            'test-secret-key'
        );

        $this->fake->assertSent(function (Request $request) use ($expectedBody, $expectedSignature) {
            return $request->method === 'POST'
                && str_contains($request->url, '/v1/verifications/ver_123/blocked-face')
                && $request->body?->bytes === $expectedBody
                && $request->header('X-HMAC-Signature') === $expectedSignature;
        });
    }

    public function test_block_face_throws_when_no_id(): void
    {
        $client = $this->makeFakedClient([
            'api.test.com/*' => FakeHttpClient::json([]),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Verification ID is required');

        $client->verifications()->blockFace();
    }
}
