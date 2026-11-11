<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Nodes\Schema;

use Neos\ContentRepository\Core\DimensionSpace;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\Schematic\Schematic;

/**
 * A dimension space point in the JSON form of Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint
 */
final readonly class DimensionSpacePoint implements ProvidesSchema
{
    private function __construct(
        public string $value,
    ) {
    }

    public static function fromString(string $value): self
    {
        return Schematic::instantiate(self::class, $value)->valueOrThrow();
    }

    public static function from(DimensionSpace\AbstractDimensionSpacePoint $dimensionSpacePoint): self
    {
        return self::fromString($dimensionSpacePoint->toJson());
    }

    /**
     * @throws \InvalidArgumentException|\RuntimeException|\TypeError if it isn't a valid dimension space point
     */
    public function toDimensionSpacePoint(): DimensionSpace\DimensionSpacePoint
    {
        return DimensionSpace\DimensionSpacePoint::fromJsonString($this->value);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= StringSchema::create(
            description: 'A point in the content dimensions, as JSON with a value for each dimension: {"language":"de"}, {} without dimensions. GET /cr/{contentRepositoryId} lists the dimensions and their values',
            examples: ['{"language":"de"}'],
            minLength: 2,
            pattern: '^\{.*\}$',
        );
    }
}
