<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Views\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\Schematic\Schematic;

final readonly class RenderingModeName implements ProvidesSchema
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
            description: 'A rendering mode of Neos: frontend, or one of the edit and preview modes in the Neos.Neos.userInterface.editPreviewModes settings, by default inPlace, rawContent and desktop',
            examples: ['frontend', 'inPlace'],
            minLength: 1,
            maxLength: 100,
            pattern: '^[a-zA-Z][a-zA-Z0-9_.-]*$',
        );
    }
}
