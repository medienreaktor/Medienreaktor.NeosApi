<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Assets\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * The collections an asset is in
 *
 * @implements \IteratorAggregate<AssetCollectionReference>
 */
final readonly class AssetCollectionReferenceList implements ProvidesSchema, \IteratorAggregate
{
    /**
     * @var list<AssetCollectionReference>
     */
    public array $assetCollections;

    public function __construct(AssetCollectionReference ...$assetCollections)
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
