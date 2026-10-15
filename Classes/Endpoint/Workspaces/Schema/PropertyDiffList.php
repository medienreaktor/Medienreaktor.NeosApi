<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * The properties a workspace changed on a node compared to its base workspace, or an event compared to before it, sorted
 * by name
 *
 * @implements \IteratorAggregate<PropertyDiff>
 */
final readonly class PropertyDiffList implements ProvidesSchema, \IteratorAggregate
{
    /**
     * @var list<PropertyDiff>
     */
    public array $propertyDiffs;

    public function __construct(PropertyDiff ...$propertyDiffs)
    {
        $this->propertyDiffs = array_values($propertyDiffs);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }

    public function getIterator(): \Traversable
    {
        yield from $this->propertyDiffs;
    }
}
