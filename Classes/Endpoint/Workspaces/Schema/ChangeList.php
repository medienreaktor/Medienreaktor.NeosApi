<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A list of changes
 *
 * @implements \IteratorAggregate<Change>
 */
final readonly class ChangeList implements ProvidesSchema, \IteratorAggregate
{
    /**
     * @var list<Change>
     */
    public array $changes;

    public function __construct(Change ...$changes)
    {
        $this->changes = array_values($changes);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }

    public function getIterator(): \Traversable
    {
        yield from $this->changes;
    }
}
