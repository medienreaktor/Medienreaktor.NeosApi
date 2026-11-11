<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Caches\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A list of caches
 *
 * @implements \IteratorAggregate<Cache>
 */
final readonly class CacheList implements ProvidesSchema, \IteratorAggregate
{
    /**
     * @var list<Cache>
     */
    public array $caches;

    public function __construct(Cache ...$caches)
    {
        $this->caches = array_values($caches);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }

    public function getIterator(): \Traversable
    {
        yield from $this->caches;
    }
}
