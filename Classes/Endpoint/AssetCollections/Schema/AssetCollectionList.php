<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\AssetCollections\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A list of asset collections
 *
 * @implements \IteratorAggregate<AssetCollection>
 */
final readonly class AssetCollectionList implements ProvidesSchema, \IteratorAggregate
{
    /**
     * @var list<AssetCollection>
     */
    public array $assetCollections;

    public function __construct(AssetCollection ...$assetCollections)
    {
        $this->assetCollections = array_values($assetCollections);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }

    public function getIterator(): \Traversable
    {
        yield from $this->assetCollections;
    }
}
