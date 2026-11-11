<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Nodes\Parameter;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\Schematic\Schematic;

/**
 * The property comparisons of filterByProperty, in the syntax of Neos\ContentRepository\Core\Projection\ContentGraph\Filter\PropertyValue\PropertyValueCriteriaParser
 */
final readonly class PropertyCriteria implements ProvidesSchema
{
    private function __construct(
        public string $value,
    ) {
    }

    public static function fromString(string $value): self
    {
        return Schematic::instantiate(self::class, $value)->valueOrThrow();
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= StringSchema::create(
            description: 'Only nodes whose properties match, in the content repository\'s syntax: comparisons like title = \'Home\', title != \'Home\', title *= \'contains\', title ^= \'starts\', title $= \'ends\', count > 3 (also >=, <, <=, values are strings in quotes, numbers or true/false), combined with AND, OR, NOT and parentheses',
            examples: ['title *= \'Neos\' AND NOT (hideInMenu = true)'],
            minLength: 1,
        );
    }
}
