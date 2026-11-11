<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Assets\Schema;

use Neos\Api\Endpoint\AssetTags\Schema\AssetTagId;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * The IDs of asset tags
 *
 * @implements \IteratorAggregate<AssetTagId>
 */
final readonly class AssetTagIdList implements ProvidesSchema, \IteratorAggregate
{
    /**
     * @var list<AssetTagId>
     */
    public array $assetTagIds;

    public function __construct(AssetTagId ...$assetTagIds)
    {
        $this->assetTagIds = array_values($assetTagIds);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }

    public function getIterator(): \Traversable
    {
        yield from $this->assetTagIds;
    }
}
