<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Tests\Resources;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProofAge\Sdk\Client;
use ProofAge\Sdk\Resources\VerificationResource;
use ProofAge\Sdk\Resources\WorkspaceResource;

/**
 * Pins the preserved surface of section 5 of the design: every public method exists with
 * the listed signature. A change here is a change to the "sacred" half of the
 * compatibility rule and must be deliberate.
 */
class ResourceSurfaceTest extends TestCase
{
    /** @return iterable<string, array{class-string, string, string}> */
    public static function signatures(): iterable
    {
        $client = Client::class;

        yield 'WorkspaceResource::__construct' => [WorkspaceResource::class, '__construct', "__construct({$client} \$client)"];
        yield 'WorkspaceResource::get' => [WorkspaceResource::class, 'get', 'get(): ?array'];
        yield 'WorkspaceResource::getConsent' => [WorkspaceResource::class, 'getConsent', 'getConsent(): ?array'];

        yield 'VerificationResource::__construct' => [VerificationResource::class, '__construct', "__construct({$client} \$client, ?string \$verificationId = null)"];
        yield 'VerificationResource::create' => [VerificationResource::class, 'create', 'create(array $data): ?array'];
        yield 'VerificationResource::find' => [VerificationResource::class, 'find', 'find(string $id): ?array'];
        yield 'VerificationResource::get' => [VerificationResource::class, 'get', 'get(): ?array'];
        yield 'VerificationResource::acceptConsent' => [VerificationResource::class, 'acceptConsent', 'acceptConsent(array $data): ?array'];
        yield 'VerificationResource::uploadMedia' => [VerificationResource::class, 'uploadMedia', 'uploadMedia(array $data): ?array'];
        yield 'VerificationResource::submit' => [VerificationResource::class, 'submit', 'submit(): ?array'];
        yield 'VerificationResource::document' => [VerificationResource::class, 'document', 'document(): ?array'];
        yield 'VerificationResource::downloadMedia' => [VerificationResource::class, 'downloadMedia', 'downloadMedia(string $mediaId): Psr\Http\Message\StreamInterface'];
        yield 'VerificationResource::downloadMediaTo' => [VerificationResource::class, 'downloadMediaTo', 'downloadMediaTo(string $mediaId, string $path): string'];
        yield 'VerificationResource::estimation' => [VerificationResource::class, 'estimation', 'estimation(): ?array'];
        yield 'VerificationResource::blockFace' => [VerificationResource::class, 'blockFace', 'blockFace(?array $data = null): ?array'];

        yield 'Client::workspace' => [Client::class, 'workspace', 'workspace(): ProofAge\Sdk\Resources\WorkspaceResource'];
        yield 'Client::verifications' => [Client::class, 'verifications', 'verifications(?string $id = null): ProofAge\Sdk\Resources\VerificationResource'];
        yield 'Client::makeRequest' => [Client::class, 'makeRequest', 'makeRequest(string $method, string $endpoint, array $data = [], array $files = []): ProofAge\Sdk\Http\Response'];
        yield 'Client::makeStreamedRequest' => [Client::class, 'makeStreamedRequest', 'makeStreamedRequest(string $method, string $endpoint, ?string $sink = null): ProofAge\Sdk\Http\Response'];
        yield 'Client::pushMiddleware' => [Client::class, 'pushMiddleware', 'pushMiddleware(callable $middleware, ?string $name = null): static'];
        yield 'Client::removeMiddleware' => [Client::class, 'removeMiddleware', 'removeMiddleware(string $name): static'];
        yield 'Client::onRequest' => [Client::class, 'onRequest', 'onRequest(callable $listener): static'];
        yield 'Client::onResponse' => [Client::class, 'onResponse', 'onResponse(callable $listener): static'];
        yield 'Client::onError' => [Client::class, 'onError', 'onError(callable $listener): static'];
        yield 'Client::transport' => [Client::class, 'transport', 'transport(): ProofAge\Sdk\Http\HttpClient'];
    }

    /** @param class-string $class */
    #[DataProvider('signatures')]
    public function test_public_method_signature_is_preserved(string $class, string $method, string $expected): void
    {
        $this->assertTrue(method_exists($class, $method), "{$class}::{$method}() is missing.");

        $reflection = new \ReflectionMethod($class, $method);

        $this->assertTrue($reflection->isPublic());
        $this->assertSame($expected, self::render($reflection));
    }

    public function test_gender_constants_are_preserved(): void
    {
        $this->assertSame(0, VerificationResource::GENDER_FEMALE);
        $this->assertSame(1, VerificationResource::GENDER_MALE);
    }

    private static function render(\ReflectionMethod $method): string
    {
        $parameters = [];

        foreach ($method->getParameters() as $parameter) {
            $rendered = ($parameter->hasType() ? (string) $parameter->getType().' ' : '').'$'.$parameter->getName();

            if ($parameter->isDefaultValueAvailable()) {
                $default = $parameter->getDefaultValue();
                $rendered .= ' = '.(is_array($default) ? '[]' : strtolower(var_export($default, true)));
            }

            $parameters[] = $rendered;
        }

        $return = $method->hasReturnType() ? ': '.(string) $method->getReturnType() : '';

        return $method->getName().'('.implode(', ', $parameters).')'.$return;
    }
}
