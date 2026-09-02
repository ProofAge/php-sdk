<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Resources;

use ProofAge\Sdk\Client;

class WorkspaceResource
{
    protected Client $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * Get workspace information.
     *
     * @return array{
     *     id: string,
     *     name: string,
     *     flow_type: string,
     *     mode: string,
     *     age_mode: string|null,
     *     age_threshold: int|null,
     *     verification_type: string,
     *     redirect_url: string|null,
     *     webhook_url: string|null,
     *     allow_expired_documents: bool,
     *     allow_duplicate_accounts: bool
     * }|null
     */
    public function get(): ?array
    {
        $response = $this->client->makeRequest('GET', 'workspace');

        return $response->json();
    }

    /**
     * Get consent information.
     *
     * @return array{id: int, version: string, text_sha256: string, url: string}|null
     */
    public function getConsent(): ?array
    {
        $response = $this->client->makeRequest('GET', 'consent');

        return $response->json();
    }
}
