<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\NodeTypes\Schema;

use Neos\Api\Infrastructure\I18n\Labels;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A reference a node type declares, the configuration has the rest of it
 */
final readonly class NodeTypeReference implements ProvidesSchema
{
    /**
     * @param string $name the key in the references configuration, e.g. relatedPages
     * @param string|null $label the label (ui.label) in the requested language, null if it has none
     * @param int|null $maxItems how many nodes it may reference (constraints.maxItems), null for any number. 1 is a singular reference, as Neos 9 declares the former reference properties
     */
    public function __construct(
        public string $name,
        public string|null $label,
        public int|null $maxItems,
    ) {
    }

    /**
     * @param array<string, mixed> $configuration
     */
    public static function from(string $name, array $configuration, Labels $labels): self
    {
        $maxItems = $configuration['constraints']['maxItems'] ?? null;
        return new self(
            $name,
            $labels->label($configuration['ui']['label'] ?? null),
            is_int($maxItems) ? $maxItems : null,
        );
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
