<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Nodes\Parameter;

use Neos\ContentRepository\Core\SharedModel;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\Schematic\Schematic;

/**
 * The node aggregate ids of filterByNodeAggregateId (listNodes, listWorkspaceEvents), comma-separated, at most MAX
 */
final readonly class NodeAggregateIds implements ProvidesSchema
{
    /**
     * As many as the largest page: a client reads more in several requests
     */
    public const int MAX = 100;

    /**
     * The rules of Nodes\Schema\NodeAggregateId
     */
    private const string ID = '[a-z0-9\-]{1,64}';

    private function __construct(
        public string $value,
    ) {
    }

    public static function fromString(string $value): self
    {
        return Schematic::instantiate(self::class, $value)->valueOrThrow();
    }

    /**
     * The ids in their order, each once
     *
     * @return list<SharedModel\Node\NodeAggregateId>
     */
    public function toNodeAggregateIds(): array
    {
        // can't throw: the schema has the same rules
        return array_map(
            static fn (string $id) => SharedModel\Node\NodeAggregateId::fromString($id),
            array_values(array_unique(explode(',', $this->value))),
        );
    }

    /**
     * Whether the id is one of them
     */
    public function contains(string $nodeAggregateId): bool
    {
        return in_array($nodeAggregateId, explode(',', $this->value), true);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= StringSchema::create(
            description: sprintf('Node aggregate ids, comma-separated, at most %d', self::MAX),
            examples: ['a3474e1d-dd60-4a84-82b1-18d2f21891a3,5a1b8c2d-3e4f-4a5b-8c6d-7e8f9a0b1c2d'],
            pattern: sprintf('^%s(,%s){0,%d}$', self::ID, self::ID, self::MAX - 1),
        );
    }
}
