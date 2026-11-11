<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A list of workspace role assignments
 *
 * @implements \IteratorAggregate<WorkspaceRoleAssignment>
 */
final readonly class WorkspaceRoleAssignmentList implements ProvidesSchema, \IteratorAggregate
{
    /**
     * @var list<WorkspaceRoleAssignment>
     */
    public array $assignments;

    public function __construct(WorkspaceRoleAssignment ...$assignments)
    {
        $this->assignments = array_values($assignments);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }

    public function getIterator(): \Traversable
    {
        yield from $this->assignments;
    }
}
