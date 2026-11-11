<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Caches\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A cache of Flow and its packages, as configured in Caches.yaml
 */
final readonly class Cache implements ProvidesSchema
{
    /**
     * @param string $frontend the class name of the cache frontend
     * @param string $backend the class name of the cache backend
     * @param bool $persistent whether the cache keeps data that isn't just a cache (encryption keys, sessions): flow:cache:flush leaves it alone, and so does flushCache
     */
    public function __construct(
        public CacheId $id,
        public string $frontend,
        public string $backend,
        public bool $persistent,
    ) {
    }

    /**
     * @param array<string,mixed> $configuration the cache's configuration, merged with the Default one
     */
    public static function from(string $identifier, array $configuration): self
    {
        return new self(
            CacheId::fromString($identifier),
            (string)$configuration['frontend'],
            (string)$configuration['backend'],
            (bool)$configuration['persistent'],
        );
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
