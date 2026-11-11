<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Nodes\Schema;

use Neos\ContentRepository\Core\Feature\SubtreeTagging\Dto;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\Schematic\Schematic;

final readonly class SubtreeTag implements ProvidesSchema
{
    private function __construct(
        public string $value,
    ) {
    }

    public static function fromString(string $value): self
    {
        return Schematic::instantiate(self::class, $value)->valueOrThrow();
    }

    public function toSubtreeTag(): Dto\SubtreeTag
    {
        return Dto\SubtreeTag::fromString($this->value);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= StringSchema::create(
            description: 'A tag of a node and everything below it. Neos hides nodes tagged disabled, node privileges may match tags as well',
            examples: ['disabled'],
            // as the content repository's SubtreeTag
            pattern: '^[a-z0-9_.-]{1,36}$',
        );
    }
}
