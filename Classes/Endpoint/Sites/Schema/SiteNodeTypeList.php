<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Sites\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * The node types a site node can have
 *
 * @implements \IteratorAggregate<SiteNodeType>
 */
final readonly class SiteNodeTypeList implements ProvidesSchema, \IteratorAggregate
{
    /**
     * @var list<SiteNodeType>
     */
    public array $nodeTypes;

    public function __construct(SiteNodeType ...$nodeTypes)
    {
        $this->nodeTypes = array_values($nodeTypes);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }

    public function getIterator(): \Traversable
    {
        yield from $this->nodeTypes;
    }
}
