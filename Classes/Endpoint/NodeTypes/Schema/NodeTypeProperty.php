<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\NodeTypes\Schema;

use Neos\Api\Infrastructure\I18n\Labels;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A property a node type declares, internal ones (starting with _, like _hidden) left out. The configuration has the
 * rest of it
 */
final readonly class NodeTypeProperty implements ProvidesSchema
{
    /**
     * @param string $name the key in the properties configuration, e.g. title
     * @param string|null $type the property type, e.g. string or Neos\Media\Domain\Model\ImageInterface, null if it has none
     * @param string|null $label the label (ui.label) in the requested language, null if it has none
     */
    public function __construct(
        public string $name,
        public string|null $type,
        public string|null $label,
    ) {
    }

    /**
     * @param array<string, mixed> $configuration
     */
    public static function from(string $name, array $configuration, Labels $labels): self
    {
        $type = $configuration['type'] ?? null;
        return new self(
            $name,
            is_string($type) ? $type : null,
            $labels->label($configuration['ui']['label'] ?? null),
        );
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
