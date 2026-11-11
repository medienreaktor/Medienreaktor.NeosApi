<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A list of workspaces
 *
 * @implements \IteratorAggregate<Workspace>
 */
final readonly class WorkspaceList implements ProvidesSchema, \IteratorAggregate
{
    /**
     * @var list<Workspace>
     */
    public array $workspaces;

    public function __construct(Workspace ...$workspaces)
    {
        $this->workspaces = array_values($workspaces);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }

    public function getIterator(): \Traversable
    {
        yield from $this->workspaces;
    }
}
