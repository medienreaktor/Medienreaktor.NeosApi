<?php

declare(strict_types=1);

namespace Neos\Api\Tests\Functional\Endpoint;

use Neos\Api\Endpoint\Caches\Caches;
use Neos\Flow\Cache\CacheManager;
use Neos\Neos\Domain\Service\UserService;
use PHPUnit\Framework\Attributes\Test;

class CachesTest extends EndpointTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $userService = $this->objectManager->get(UserService::class);
        $userService->createUser('admin', 'password', 'Ada', 'Admin', ['Neos.Neos:Administrator']);
        $userService->createUser('editor', 'password', 'Edith', 'Editor', ['Neos.Neos:Editor']);
        $this->addMachineClient('admin-machine', 'admin');
        $this->addMachineClient('editor-machine', 'editor');
        $this->persistenceManager->persistAll();
    }

    #[Test]
    public function listsTheConfiguredCaches(): void
    {
        $response = $this->get('/api/caches', $this->token('admin-machine', 'caches.read'));

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        $caches = self::json($response)['data'];
        self::assertSame(count($caches), self::json($response)['meta']['total']);
        $ids = array_column($caches, 'id');
        self::assertContains('Neos_Fusion_Content', $ids);
        self::assertNotContains('Default', $ids);
        $sorted = $ids;
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $ids);

        $byId = array_column($caches, null, 'id');
        self::assertTrue($byId['Flow_Session_Storage']['persistent']);
        self::assertFalse($byId['Neos_Fusion_Content']['persistent']);
        self::assertNotSame('', $byId['Neos_Fusion_Content']['frontend']);
        self::assertNotSame('', $byId['Neos_Fusion_Content']['backend']);
    }

    #[Test]
    public function flushesACache(): void
    {
        $cache = $this->objectManager->get(CacheManager::class)->getCache('Neos_Fusion_Content');
        $cache->set('neos-api-test', 'cached');

        $response = $this->post('/api/caches/Neos_Fusion_Content/flush', $this->token('admin-machine', 'caches.flush'), []);

        self::assertSame(204, $response->getStatusCode(), (string)$response->getBody());
        self::assertFalse($cache->has('neos-api-test'));
    }

    #[Test]
    public function flushesAllCachesButThePersistentOnes(): void
    {
        // a real flush would remove the proxy classes the rest of the test run needs
        $cacheManager = $this->objectManager->get(CacheManager::class);
        $spy = $this->createMock(CacheManager::class);
        $spy->expects(self::once())->method('flushCaches')->with(false);
        $this->objectManager->setInstance(CacheManager::class, $spy);
        $this->objectManager->forgetInstance(Caches::class);
        try {
            $response = $this->post('/api/caches/flush', $this->token('admin-machine', 'caches.flush'), []);
        } finally {
            $this->objectManager->setInstance(CacheManager::class, $cacheManager);
            $this->objectManager->forgetInstance(Caches::class);
        }

        self::assertSame(204, $response->getStatusCode(), (string)$response->getBody());
    }

    #[Test]
    public function flushingAllCachesIsDeniedToAccountsWithoutThePrivilege(): void
    {
        $response = $this->post('/api/caches/flush', $this->token('editor-machine', 'caches.flush'), []);

        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('Neos.Api:Caches.Flush', self::json($response)['detail']);
    }

    #[Test]
    public function persistentCachesAreNotFlushed(): void
    {
        $cache = $this->objectManager->get(CacheManager::class)->getCache('Flow_Session_MetaData');
        $cache->set('neos-api-test', 'kept');
        try {
            $response = $this->post('/api/caches/Flow_Session_MetaData/flush', $this->token('admin-machine', 'caches.flush'), []);

            self::assertSame(422, $response->getStatusCode(), (string)$response->getBody());
            self::assertTrue($cache->has('neos-api-test'));
        } finally {
            $cache->remove('neos-api-test');
        }
    }

    #[Test]
    public function unknownCachesAreNotFound(): void
    {
        $response = $this->post('/api/caches/Unknown_Cache/flush', $this->token('admin-machine', 'caches.flush'), []);
        self::assertSame(404, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('There is no cache with the ID Unknown_Cache', self::json($response)['detail']);

        // Default is the configuration the caches are merged with, not a cache
        self::assertSame(404, $this->post('/api/caches/Default/flush', $this->token('admin-machine', 'caches.flush'), [])->getStatusCode());
    }

    #[Test]
    public function rejectsInvalidIds(): void
    {
        self::assertSame(400, $this->post('/api/caches/not.a.cache/flush', $this->token('admin-machine', 'caches.flush'), [])->getStatusCode());
    }

    #[Test]
    public function isDeniedToAccountsWithoutThePrivilege(): void
    {
        $response = $this->get('/api/caches', $this->token('editor-machine', 'caches.read'));
        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('Neos.Api:Caches.Read', self::json($response)['detail']);

        $response = $this->post('/api/caches/Neos_Fusion_Content/flush', $this->token('editor-machine', 'caches.flush'), []);
        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('Neos.Api:Caches.Flush', self::json($response)['detail']);
    }

    #[Test]
    public function requiresTheScope(): void
    {
        $response = $this->post('/api/caches/Neos_Fusion_Content/flush', $this->token('admin-machine', 'caches.read'), []);

        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('caches.flush', self::json($response)['detail']);
    }

    #[Test]
    public function requiresAToken(): void
    {
        self::assertSame(401, $this->get('/api/caches', null)->getStatusCode());
    }
}
