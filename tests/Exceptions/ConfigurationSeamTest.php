<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Tests\Exceptions;

use PHPUnit\Framework\TestCase;
use ProofAge\Sdk\Client;
use ProofAge\Sdk\Exceptions\DefaultExceptionFactory;
use ProofAge\Sdk\Exceptions\ExceptionFactory;
use ProofAge\Sdk\Exceptions\ProofAgeException;
use ProofAge\Sdk\Http\Response;
use ProofAge\Sdk\Testing\FakeHttpClient;

/*
 * Section 7.4 of the design says Client never names an exception class directly.
 * Responses already go through the seam; the errors that have no response — an
 * incomplete configuration and an unencodable body — must go through it too, or a
 * host framework cannot make them its own classes. A consumer catching the host's
 * exception around client construction would otherwise miss.
 */
class ConfigurationSeamTest extends TestCase
{
    public function test_configuration_errors_are_built_by_the_factory(): void
    {
        $this->expectException(HostConfigurationException::class);
        $this->expectExceptionMessage('API key is required');

        new Client(['secret_key' => 's', 'base_url' => 'https://api.test'], new FakeHttpClient, new HostExceptionFactory);
    }

    public function test_an_unencodable_body_is_built_by_the_factory(): void
    {
        $client = new Client(
            ['api_key' => 'k', 'secret_key' => 's', 'base_url' => 'https://api.test'],
            new FakeHttpClient,
            new HostExceptionFactory,
        );

        $this->expectException(HostConfigurationException::class);

        $client->verifications()->create(['bad' => "\xB1\x31"]);
    }

    public function test_the_default_factory_still_produces_the_documented_messages(): void
    {
        $this->expectException(ProofAgeException::class);
        $this->expectExceptionMessage('Secret key is required');

        new Client(['api_key' => 'k', 'base_url' => 'https://api.test'], new FakeHttpClient, new DefaultExceptionFactory);
    }
}

class HostConfigurationException extends ProofAgeException {}

class HostExceptionFactory implements ExceptionFactory
{
    public function fromResponse(Response $response): ProofAgeException
    {
        return new HostConfigurationException($response->body());
    }

    public function configuration(string $message, ?\Throwable $previous = null): ProofAgeException
    {
        return new HostConfigurationException($message, 0, $previous);
    }
}
