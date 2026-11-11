<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Sites\Schema;

use Neos\Api\Shared\Schema\PackageKey;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A list of Flow package keys
 *
 * @implements \IteratorAggregate<PackageKey>
 */
final readonly class PackageKeyList implements ProvidesSchema, \IteratorAggregate
{
    /**
     * @var list<PackageKey>
     */
    public array $packageKeys;

    public function __construct(PackageKey ...$packageKeys)
    {
        $this->packageKeys = array_values($packageKeys);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }

    public function getIterator(): \Traversable
    {
        yield from $this->packageKeys;
    }
}
