<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\Schema;

use Neos\Api\Endpoint\Nodes\Schema\NodeAggregateId;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * The aggregate ids of documents, e.g. the ones whose changes to publish or discard
 *
 * @implements \IteratorAggregate<NodeAggregateId>
 */
final readonly class DocumentAggregateIdList implements ProvidesSchema, \IteratorAggregate
{
    /**
     * @var list<NodeAggregateId>
     */
    public array $documentAggregateIds;

    public function __construct(NodeAggregateId ...$documentAggregateIds)
    {
        $this->documentAggregateIds = array_values($documentAggregateIds);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }

    public function getIterator(): \Traversable
    {
        yield from $this->documentAggregateIds;
    }
}
