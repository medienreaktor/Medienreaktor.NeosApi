<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\NodeTypes\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * The references a node type declares
 *
 * @implements \IteratorAggregate<NodeTypeReference>
 */
final readonly class NodeTypeReferenceList implements ProvidesSchema, \IteratorAggregate
{
    /**
     * @var list<NodeTypeReference>
     */
    public array $references;

    public function __construct(NodeTypeReference ...$references)
    {
        $this->references = array_values($references);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }

    public function getIterator(): \Traversable
    {
        yield from $this->references;
    }
}
