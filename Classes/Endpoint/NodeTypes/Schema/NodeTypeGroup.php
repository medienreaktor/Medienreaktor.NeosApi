<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\NodeTypes\Schema;

use Neos\Api\Infrastructure\I18n\Labels;
use Neos\JsonSchema\AnyOfSchema;
use Neos\JsonSchema\BooleanSchema;
use Neos\JsonSchema\IntegerSchema;
use Neos\JsonSchema\NullSchema;
use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\JsonSchema\Support\ObjectProperties;

/**
 * A group the Neos UI offers node types in for creation (Neos.Neos.nodeTypes.groups), the one of a node type's group
 *
 * The schema is written out: position is an integer or a string, as NodeType's
 */
final readonly class NodeTypeGroup implements ProvidesSchema
{
    public function __construct(
        public string $name,
        public string $label,
        public int|string|null $position,
        public bool $collapsed,
    ) {
    }

    /**
     * @param array<string, mixed> $configuration the group's settings
     */
    public static function from(string $name, array $configuration, Labels $labels): self
    {
        $position = $configuration['position'] ?? null;
        return new self(
            $name,
            $labels->label($configuration['label'] ?? null) ?? $name,
            is_int($position) || is_string($position) ? $position : null,
            ($configuration['collapsed'] ?? false) === true,
        );
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= ObjectSchema::create(
            description: 'A group of node types the Neos UI offers for creation, from the Neos.Neos.nodeTypes.groups settings',
            properties: ObjectProperties::create(
                name: StringSchema::create(description: 'The group\'s key in the settings, a node type\'s group', examples: ['general']),
                label: StringSchema::create(description: 'The label in the requested language, the name if it has none'),
                position: AnyOfSchema::create(
                    IntegerSchema::create(description: 'The position among the groups as a number'),
                    StringSchema::create(description: 'The position among the groups as a position string', examples: ['start', 'after general']),
                    NullSchema::create(),
                ),
                collapsed: BooleanSchema::create(description: 'Whether the Neos UI shows the group collapsed'),
            ),
            additionalProperties: false,
            required: ['name', 'label', 'position', 'collapsed'],
        );
    }
}
