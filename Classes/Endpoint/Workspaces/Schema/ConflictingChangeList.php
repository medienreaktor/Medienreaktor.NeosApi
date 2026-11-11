<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * The conflicting changes of a publish, discard or rebase, one per node the account may read
 *
 * @implements \IteratorAggregate<ConflictingChange>
 */
final readonly class ConflictingChangeList implements ProvidesSchema, \IteratorAggregate
{
    /**
     * @var list<ConflictingChange>
     */
    public array $conflictingChanges;

    public function __construct(ConflictingChange ...$conflictingChanges)
    {
        $this->conflictingChanges = array_values($conflictingChanges);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }

    public function getIterator(): \Traversable
    {
        yield from $this->conflictingChanges;
    }
}
