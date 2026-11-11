<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Sites\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\Schematic\Schematic;

final readonly class SiteName implements ProvidesSchema
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
            description: 'The human readable name of a site',
            examples: ['Neos Demo Site'],
            minLength: 1,
            maxLength: 250,
            // the characters of Flow's LabelValidator, which the Site entity is validated with
            pattern: '^[\\p{L}\\p{Sc} ,.:;?!%§&"\'/+\\-_=()#0-9]*$',
        );
    }
}
