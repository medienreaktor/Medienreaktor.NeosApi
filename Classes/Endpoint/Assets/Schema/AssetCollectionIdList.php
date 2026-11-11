<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Assets\Schema;

use Neos\Api\Endpoint\AssetCollections\Schema\AssetCollectionId;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * The IDs of asset collections
 *
 * @implements \IteratorAggregate<AssetCollectionId>
 */
final readonly class AssetCollectionIdList implements ProvidesSchema, \IteratorAggregate
{
    /**
     * @var list<AssetCollectionId>
     */
    public array $assetCollectionIds;

    public function __construct(AssetCollectionId ...$assetCollectionIds)
    {
        $this->assetCollectionIds = array_values($assetCollectionIds);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }

    public function getIterator(): \Traversable
    {
        yield from $this->assetCollectionIds;
    }
}
