<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Assets\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A list of assets
 *
 * @implements \IteratorAggregate<Asset>
 */
final readonly class AssetList implements ProvidesSchema, \IteratorAggregate
{
    /**
     * @var list<Asset>
     */
    public array $assets;

    public function __construct(Asset ...$assets)
    {
        $this->assets = array_values($assets);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }

    public function getIterator(): \Traversable
    {
        yield from $this->assets;
    }
}
