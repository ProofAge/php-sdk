<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Resources;

use ProofAge\Sdk\Client;
use ProofAge\Sdk\Enums\BlockFaceReasonCode;
use ProofAge\Sdk\Enums\VerificationStatus;
use ProofAge\Sdk\Http\Body\FilePart;
use ProofAge\Sdk\Http\Response;
use Psr\Http\Message\StreamInterface;

class VerificationResource
{
    public const GENDER_FEMALE = 0;

    public const GENDER_MALE = 1;

    protected Client $client;

    protected ?string $verificationId;

    public function __construct(Client $client, ?string $verificationId = null)
    {
        $this->client = $client;
        $this->verificationId = $verificationId;
    }

    /**
     * Create a new verification.
     *
     * `callback_url` is where the person's browser is sent when they finish, overriding the
     * workspace's redirect URL for this verification (it comes back as `redirect_url`). It
     * is not a webhook: status webhooks always go to the workspace's webhook URL.
     * `fingerprint` and `page_url` are deprecated: the ProofAge widget sends them, they are not
     * part of the public API, and they will be removed from this shape in a future minor release.
     *
     * `status` is one of the {@see VerificationStatus} values; read it with tryFrom().
     * `duplicate_check` lists the accounts whose face matched this one (`duplicate_count`
     * spans every match, the list may be shorter). `erasure` is null until the personal data
     * is erased, then says when and why.
     *
     * @param  array{
     *     fingerprint?: string,
     *     callback_url?: string,
     *     external_id?: string,
     *     external_metadata?: array<string, mixed>,
     *     metadata?: array<string, mixed>,
     *     page_url?: string
     * }  $data
     * @return array{
     *     id: string,
     *     external_id: string|null,
     *     external_metadata: array<string, mixed>|null,
     *     redirect_url: string|null,
     *     status: string,
     *     reason: string|null,
     *     duplicate_check: array{
     *         checked: bool,
     *         duplicate_count: int,
     *         duplicates: list<array{verification_id: string|null, external_id: string|null, similarity_score: float|null, verified_at: string|null}>
     *     },
     *     erasure: array{erased_at: string, scope: string, reason: string|null, requested_via: string|null}|null,
     *     consent_accepted_at: string|null,
     *     created_at: string,
     *     updated_at: string,
     *     url: string
     * }|null
     */
    public function create(array $data): ?array
    {
        $response = $this->client->makeRequest('POST', 'verifications', $data);

        return $response->json();
    }

    /**
     * Get verification by ID. The same shape as create(), without `url`.
     *
     * @return array{
     *     id: string,
     *     external_id: string|null,
     *     external_metadata: array<string, mixed>|null,
     *     redirect_url: string|null,
     *     status: string,
     *     reason: string|null,
     *     duplicate_check: array{
     *         checked: bool,
     *         duplicate_count: int,
     *         duplicates: list<array{verification_id: string|null, external_id: string|null, similarity_score: float|null, verified_at: string|null}>
     *     },
     *     erasure: array{erased_at: string, scope: string, reason: string|null, requested_via: string|null}|null,
     *     consent_accepted_at: string|null,
     *     created_at: string,
     *     updated_at: string
     * }|null
     */
    public function find(string $id): ?array
    {
        $response = $this->client->makeRequest('GET', "verifications/{$id}");

        return $response->json();
    }

    /**
     * Get current verification (if ID was set in constructor). The same shape as find().
     *
     * @return array{
     *     id: string,
     *     external_id: string|null,
     *     external_metadata: array<string, mixed>|null,
     *     redirect_url: string|null,
     *     status: string,
     *     reason: string|null,
     *     duplicate_check: array{
     *         checked: bool,
     *         duplicate_count: int,
     *         duplicates: list<array{verification_id: string|null, external_id: string|null, similarity_score: float|null, verified_at: string|null}>
     *     },
     *     erasure: array{erased_at: string, scope: string, reason: string|null, requested_via: string|null}|null,
     *     consent_accepted_at: string|null,
     *     created_at: string,
     *     updated_at: string
     * }|null
     */
    public function get(): ?array
    {
        if (! $this->verificationId) {
            throw new \InvalidArgumentException('Verification ID is required');
        }

        return $this->find($this->verificationId);
    }

    /**
     * Accept consent for verification.
     *
     * `consent_version_id` and `text_sha256` come from WorkspaceResource::getConsent(). The
     * other fields are deprecated: the ProofAge widget sends them, they are not part of the
     * public API, and they will be removed from this shape in a future minor release.
     *
     * @param  array{
     *     consent_version_id: int,
     *     text_sha256: string,
     *     device?: array{platform?: string, screen?: string, language?: string, timezone?: string, hardware_concurrency?: int|float, device_memory?: int|float},
     *     in_app_browser?: string,
     *     camera_permission?: 'granted'|'denied'|'prompt'|'unsupported',
     *     camera_policy_allowed?: bool,
     *     in_iframe?: bool,
     *     referrer?: string
     * }  $data
     * @return array{consent_version_id: int, consent_accepted_at: string}|null
     */
    public function acceptConsent(array $data): ?array
    {
        if (! $this->verificationId) {
            throw new \InvalidArgumentException('Verification ID is required');
        }

        $response = $this->client->makeRequest(
            'POST',
            "verifications/{$this->verificationId}/consent",
            $data
        );

        return $response->json();
    }

    /**
     * Upload media for verification.
     *
     * `file` is sent as multipart. `side` and `document` are required when `type` is
     * `document`. `capture_resolution` and `device_info` are JSON-encoded strings;
     * `liveness_telemetry` is a JSON-encoded list of head-turn samples a capture widget
     * records, and is ignored when malformed. A null field is not sent and a boolean is
     * sent as 1 / 0.
     *
     * `file` may be a path, any \SplFileInfo (Illuminate\Http\UploadedFile and Symfony's
     * UploadedFile included; their client original name is used as the filename), or a
     * FilePart. A path that does not exist throws \InvalidArgumentException.
     *
     * `liveness_selfie` and the fields after `document` are deprecated: the ProofAge widget sends
     * them, they are not part of the public API, and they will be removed from this shape in a
     * future minor release. Upload a person's selfie as `selfie`.
     *
     * The API answers 200 with an empty body, so this returns null. A failed quality check
     * throws a ValidationException whose getErrorCode() names it (FACE_NOT_FOUND, ...).
     *
     * @param  array{
     *     file?: \SplFileInfo|FilePart|string,
     *     type: 'selfie'|'liveness_selfie'|'document',
     *     side?: 'front'|'back',
     *     document?: 'id'|'driver_license'|'passport'|'residence_permit',
     *     fingerprint?: string,
     *     head_turn_step?: int,
     *     capture_resolution?: string,
     *     device_info?: string,
     *     liveness_telemetry?: string
     * }  $data
     * @return array{}|null Always null: the API answers 200 with an empty body. The ?array
     *                      return type is kept so existing subclasses stay compatible.
     */
    public function uploadMedia(array $data): ?array
    {
        if (! $this->verificationId) {
            throw new \InvalidArgumentException('Verification ID is required');
        }

        $files = [];
        $formData = $data;

        // Extract files from data
        if (isset($data['file'])) {
            $files['file'] = $data['file'];
            unset($formData['file']);
        }

        $this->client->makeRequest(
            'POST',
            "verifications/{$this->verificationId}/media",
            $formData,
            $files
        );

        return null;
    }

    /**
     * Submit verification for processing.
     *
     * The API answers 200 with an empty body, so this returns null; the outcome arrives by
     * webhook or through get(). A verification that is not `started`, or lacks required
     * media, throws a ValidationException (`error.code`, e.g. MISSING_REQUIRED_MEDIA).
     *
     * @return array{}|null Always null: the API answers 200 with an empty body. The ?array
     *                      return type is kept so existing subclasses stay compatible.
     */
    public function submit(): ?array
    {
        if (! $this->verificationId) {
            throw new \InvalidArgumentException('Verification ID is required');
        }

        $this->client->makeRequest(
            'POST',
            "verifications/{$this->verificationId}/submit"
        );

        return null;
    }

    /**
     * Get sanitized document fields and source media for verification.
     *
     * Media are ordered selfie, document_front, document_back; each is `{id, type, url}`.
     * Fetch the bytes with
     * downloadMedia() using `media[].id`; `url` is that endpoint's address and is
     * null when the media has been purged or has passed its retention window.
     *
     * @return array{
     *     document: array{fields: array{first_name: string|null, last_name: string|null, date_of_birth: string|null, document_number: string|null}},
     *     media: list<array{id: string, type: 'selfie'|'document_front'|'document_back', url: string|null}>,
     *     meta: array{attempt_id: string|null}
     * }|null
     */
    public function document(): ?array
    {
        if (! $this->verificationId) {
            throw new \InvalidArgumentException('Verification ID is required');
        }

        $response = $this->client->makeRequest(
            'GET',
            "verifications/{$this->verificationId}/document"
        );

        return $response->json();
    }

    /**
     * Download one media file belonging to the verification.
     *
     * Streams the bytes from the ProofAge API under the same API key and HMAC
     * signature as every other call. `media[].url` from document() is this endpoint's
     * address; it needs the same signed request, so fetch it through this method.
     *
     * The media ID is `media[].id` from document(). Media that has been purged,
     * has passed its retention window, or does not belong to this verification
     * answers 404, which surfaces as a ProofAgeException.
     *
     * Do not assume the body fits in memory: with the bundled cURL transport it is
     * received into php://temp, which spills to disk past 2 MB, and never held as a
     * PHP string. Whether it is read lazily from the network depends on the
     * transport; cURL's easy interface receives it fully before returning.
     *
     * @param  string  $mediaId  Media UUID from document()
     * @return StreamInterface Body stream; do not assume it fits in memory
     */
    public function downloadMedia(string $mediaId): StreamInterface
    {
        return $this->mediaResponse($mediaId)->getBody();
    }

    /**
     * Download one media file straight to disk.
     *
     * Never holds the whole file in memory, so it stays safe as media grows. The bytes
     * are received into a temporary file next to $path and renamed over it only once
     * the API answered 2xx; on a transport failure or an error status nothing is left
     * at $path (and a previous file there is untouched), so a caller who does not
     * check never finds an error body or an empty file under the media's name.
     *
     * @param  string  $path  Absolute destination path
     * @return string The path written to
     */
    public function downloadMediaTo(string $mediaId, string $path): string
    {
        $this->mediaResponse($mediaId, $path);

        return $path;
    }

    private function mediaResponse(string $mediaId, ?string $sink = null): Response
    {
        if (! $this->verificationId) {
            throw new \InvalidArgumentException('Verification ID is required');
        }

        return $this->client->makeStreamedRequest(
            'GET',
            "verifications/{$this->verificationId}/media/{$mediaId}",
            $sink,
        );
    }

    /**
     * Get sanitized age estimation and gender result for verification.
     *
     * Gender value mapping: GENDER_FEMALE (0) means female, GENDER_MALE (1) means male.
     *
     * @return array{
     *     verification_id: string,
     *     attempt_id: string|null,
     *     age_threshold: array{
     *         minimum: int|null,
     *         passed: bool|null,
     *         confidence: float|null
     *     },
     *     gender: array{
     *         value: self::GENDER_FEMALE|self::GENDER_MALE|null,
     *         confidence: float|null
     *     }|null
     * }|null
     */
    public function estimation(): ?array
    {
        if (! $this->verificationId) {
            throw new \InvalidArgumentException('Verification ID is required');
        }

        $response = $this->client->makeRequest(
            'GET',
            "verifications/{$this->verificationId}/estimation"
        );

        return $response->json();
    }

    /**
     * Block the verification face for future AML checks.
     *
     * The API responds 204 No Content, so this returns null.
     *
     * `reason_code` classifies the block and is what blocklist reporting counts;
     * pass a {@see BlockFaceReasonCode} value whenever a
     * person made the decision. `reason` is free-text detail, truncated to 1000
     * characters rather than rejected.
     *
     * @param  array{reason?: string, reason_code?: string}|null  $data
     * @return array<string, mixed>|null Always null today: the API answers 204
     */
    public function blockFace(?array $data = null): ?array
    {
        if (! $this->verificationId) {
            throw new \InvalidArgumentException('Verification ID is required');
        }

        $response = $this->client->makeRequest(
            'POST',
            "verifications/{$this->verificationId}/blocked-face",
            $data ?? []
        );

        return $response->json();
    }
}
