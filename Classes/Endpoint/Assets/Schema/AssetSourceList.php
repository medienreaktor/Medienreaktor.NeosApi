<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Assets\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A list of asset sources
 *
 * @implements \IteratorAggregate<AssetSource>
 */
final readonly class AssetSourceList implements ProvidesSchema, \IteratorAggregate
{
    /**
     * @var list<AssetSource>
     */
    public array $assetSources;

    public function __construct(AssetSource ...$assetSources)
    {
        $this->assetSources = array_values($assetSources);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }

    public function getIterator(): \Traversable
    {
        yield from $this->assetSources;
    }
}
