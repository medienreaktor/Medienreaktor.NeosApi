<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Nodes\Schema;

use Neos\ContentRepository\Core\SharedModel;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\Schematic\Schematic;

final readonly class NodeAggregateId implements ProvidesSchema
{
    private function __construct(
        public string $value,
    ) {
    }

    public static function fromString(string $value): self
    {
        return Schematic::instantiate(self::class, $value)->valueOrThrow();
    }

    public function toNodeAggregateId(): SharedModel\Node\NodeAggregateId
    {
        // can't throw: the schema has the same rules
        return SharedModel\Node\NodeAggregateId::fromString($this->value);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        // the rules of Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId
        return $schema ??= StringSchema::create(
            description: 'The id of a node aggregate: the node in all its workspaces and dimension space points',
            examples: ['a3474e1d-dd60-4a84-82b1-18d2f21891a3'],
            minLength: 1,
            maxLength: 64,
            pattern: '^[a-z0-9\-]+$',
        );
    }
}
