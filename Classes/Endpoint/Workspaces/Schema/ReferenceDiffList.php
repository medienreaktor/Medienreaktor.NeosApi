<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * The references a workspace changed on a node, compared to its base workspace, sorted by name
 *
 * @implements \IteratorAggregate<ReferenceDiff>
 */
final readonly class ReferenceDiffList implements ProvidesSchema, \IteratorAggregate
{
    /**
     * @var list<ReferenceDiff>
     */
    public array $referenceDiffs;

    public function __construct(ReferenceDiff ...$referenceDiffs)
    {
        $this->referenceDiffs = array_values($referenceDiffs);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }

    public function getIterator(): \Traversable
    {
        yield from $this->referenceDiffs;
    }
}
