<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Caches;

use Neos\Api\Endpoint\Caches\Schema\Cache;
use Neos\Api\Endpoint\Caches\Schema\CacheId;
use Neos\Api\Endpoint\Caches\Schema\CacheList;
use Neos\Api\Endpoint\Caches\Schema\CacheListing;
use Neos\Api\Security\ApiAuthContextProvider;
use Neos\Api\Security\ApiScopes;
use Neos\Api\Shared\Response\NotFound;
use Neos\Api\Shared\Response\UnprocessableContent;
use Neos\Flow\Cache\CacheManager;
use Neos\OpenApi\Attributes\Operation;

/**
 * The caches of Flow and its packages, configured in Caches.yaml
 */
final readonly class Caches
{
    public function __construct(
        private CacheManager $cacheManager,
    ) {
    }

    #[Operation(
        path: '/caches',
        method: 'GET',
        summary: 'List all caches',
        description: 'The caches configured in Caches.yaml, sorted by their ID.',
        operationId: 'listCaches',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::CACHES_READ],
        ],
    )]
    public function list(): CacheListing
    {
        $caches = [];
        foreach ($this->configurations() as $identifier => $configuration) {
            $caches[] = Cache::from($identifier, $configuration);
        }
        return CacheListing::of(new CacheList(...$caches));
    }

    #[Operation(
        path: '/caches/flush',
        method: 'POST',
        summary: 'Flush all caches',
        description: 'Removes all entries of all caches but the persistent ones (encryption keys, sessions), as flow:cache:flush without --force does, the compiled configuration included. The next requests rebuild Flow\'s own caches (proxy classes, reflection, configuration), which takes a while.',
        operationId: 'flushCaches',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::CACHES_FLUSH],
        ],
    )]
    public function flushAll(): null
    {
        $this->cacheManager->flushCaches(false);
        return null;
    }

    #[Operation(
        path: '/caches/{cacheId}/flush',
        method: 'POST',
        summary: 'Flush a cache',
        description: 'Removes all entries of the cache, as flow:cache:flushone does. A persistent cache (encryption keys, sessions) is a 422: flushing it would invalidate signed data or log everybody out, flow:cache:flush leaves it alone as well. Flushing one of Flow\'s own caches (Flow_Object_Classes, Flow_Reflection_*, …) makes the next request rebuild it, which takes a while.',
        operationId: 'flushCache',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::CACHES_FLUSH],
        ],
    )]
    public function flush(CacheId $cacheId): NotFound|UnprocessableContent|null
    {
        $configuration = $this->configurations()[$cacheId->value] ?? null;
        if ($configuration === null) {
            return NotFound::cache($cacheId->value);
        }
        if ((bool)$configuration['persistent']) {
            return UnprocessableContent::because(sprintf('The cache %s is persistent, it isn\'t flushed', $cacheId->value));
        }
        $this->cacheManager->getCache($cacheId->value)->flush();
        return null;
    }

    /**
     * The configured caches by ID, sorted, each merged with the Default configuration (which isn't a cache itself)
     *
     * @return array<string,array<string,mixed>>
     */
    private function configurations(): array
    {
        $configurations = $this->cacheManager->getCacheConfigurations();
        $default = $configurations['Default'] ?? [];
        unset($configurations['Default']);
        ksort($configurations, SORT_STRING);
        return array_map(static fn (array $configuration) => $configuration + $default, $configurations);
    }
}
