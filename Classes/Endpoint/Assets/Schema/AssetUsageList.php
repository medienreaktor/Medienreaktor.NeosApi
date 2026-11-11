<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Assets\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * The usages of an asset
 *
 * @implements \IteratorAggregate<AssetUsage>
 */
final readonly class AssetUsageList implements ProvidesSchema, \IteratorAggregate
{
    /**
     * @var list<AssetUsage>
     */
    public array $assetUsages;

    public function __construct(AssetUsage ...$assetUsages)
    {
        $this->assetUsages = array_values($assetUsages);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }

    public function getIterator(): \Traversable
    {
        yield from $this->assetUsages;
    }
}
