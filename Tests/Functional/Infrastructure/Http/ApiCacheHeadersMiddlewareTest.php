<?php

declare(strict_types=1);

namespace Neos\Api\Tests\Functional\Infrastructure\Http;

use Neos\Api\Tests\Functional\Endpoint\EndpointTestCase;
use Neos\Neos\Domain\Service\UserService;
use PHPUnit\Framework\Attributes\Test;

class ApiCacheHeadersMiddlewareTest extends EndpointTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->objectManager->get(UserService::class)->createUser('editor', 'password', 'Edith', 'Editor', ['Neos.Neos:Editor']);
        $this->addMachineClient('editor-machine', 'editor');
        $this->persistenceManager->persistAll();
    }

    #[Test]
    public function keepsOperationsOutOfSharedCaches(): void
    {
        $response = $this->get('/api/me', $this->token('editor-machine', 'me.read'), ['Accept-Language' => 'de']);

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame(['Authorization'], $response->getHeader('Vary'));
        // it has no labels
        self::assertFalse($response->hasHeader('Content-Language'));
    }

    #[Test]
    public function addsTheHeadersToChallengesAndProblems(): void
    {
        $response = $this->get('/api/me', null);
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame(['Authorization'], $response->getHeader('Vary'));

        $response = $this->get('/api/users', $this->token('editor-machine', 'me.read'));
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame(['Authorization'], $response->getHeader('Vary'));
    }

    #[Test]
    public function leavesTheOpenApiDocumentAlone(): void
    {
        $response = $this->get('/api/openapi.json', null);

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($response->hasHeader('Vary'));
        self::assertStringNotContainsString('no-store', $response->getHeaderLine('Cache-Control'));
    }
}
