<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Sites\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\Schematic\Schematic;

final readonly class SiteNodeName implements ProvidesSchema
{
    public const int MAX_LENGTH = 250;

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
            description: 'The node name of a site\'s node in the content repository, which identifies the site',
            examples: ['neosdemo'],
            minLength: 1,
            maxLength: self::MAX_LENGTH,
            pattern: '^[a-z0-9-]+$',
        );
    }
}
