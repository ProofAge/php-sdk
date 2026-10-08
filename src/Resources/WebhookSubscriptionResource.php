<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Resources;

use ProofAge\Sdk\Client;

/**
 * Webhook subscriptions (REST hooks): URLs that receive the decision webhooks in addition to
 * the workspace webhook URL set in the console. Subscribe when an automation is turned on,
 * delete the subscription when it is turned off. A workspace can have up to 50.
 */
class WebhookSubscriptionResource
{
    protected Client $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * Subscribe a URL to the decision webhooks.
     *
     * `url` must be a public http(s) URL of at most 2048 characters. `statuses` limits the
     * deliveries to these decision statuses (`approved`, `declined`, `resubmission_requested`,
     * `review`, `abandoned`, `expired`); omit it, or send null, for all of them.
     * `include_document_data` (default false) adds `document`, `fingerprint_signals` and
     * `manual_moderation.performed_by` to the body; without it they are left out.
     *
     * Each delivery has the workspace webhook's body and headers, signed with the secret key
     * this client signs with while that key exists, and with the active secret key after it is
     * deleted. A delivery answered with `410 Gone` deletes the subscription.
     *
     * The API answers 201. `statuses` comes back null when the subscription receives every
     * decision status. A workspace that already has 50 throws a ValidationException with
     * getErrorCode() WEBHOOK_SUBSCRIPTION_LIMIT.
     *
     * @param  array{
     *     url: string,
     *     statuses?: list<'approved'|'declined'|'resubmission_requested'|'review'|'abandoned'|'expired'>|null,
     *     include_document_data?: bool
     * }  $data
     * @return array{
     *     id: string,
     *     url: string,
     *     statuses: list<string>|null,
     *     include_document_data: bool,
     *     created_at: string
     * }|null
     */
    public function create(array $data): ?array
    {
        $response = $this->client->makeRequest('POST', 'webhook-subscriptions', $data);

        return $response->json();
    }

    /**
     * The workspace's webhook subscriptions, newest first, each in the shape create() returns.
     * Not paginated.
     *
     * @return array{
     *     data: list<array{
     *         id: string,
     *         url: string,
     *         statuses: list<string>|null,
     *         include_document_data: bool,
     *         created_at: string
     *     }>
     * }|null
     */
    public function list(): ?array
    {
        $response = $this->client->makeRequest('GET', 'webhook-subscriptions');

        return $response->json();
    }

    /**
     * Delete a subscription: the deliveries to it stop, and deliveries already queued are not
     * sent. The API answers 204 No Content. An id that is not one of the workspace's
     * subscriptions throws a ProofAgeException with getCode() 404.
     *
     * Like every DELETE, this is retried after a 5xx or a timeout. If the first attempt did
     * delete the subscription, the retry answers 404.
     */
    public function delete(string $id): void
    {
        $this->client->makeRequest('DELETE', "webhook-subscriptions/{$id}");
    }
}
