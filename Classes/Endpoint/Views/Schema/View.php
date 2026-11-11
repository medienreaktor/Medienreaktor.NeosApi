<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Views\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A view that can be rendered for a node: data a Fusion prototype builds, e.g. a navigation with grouping folders
 */
final readonly class View implements ProvidesSchema
{
    /**
     * @param string|null $description what the view contains, from its settings
     */
    public function __construct(
        public ViewName $name,
        public string|null $description,
    ) {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
