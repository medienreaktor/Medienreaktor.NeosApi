<?php

declare(strict_types=1);

namespace Neos\Api\Shared\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\Schematic\Schematic;

/**
 * The Accept-Language header, the languages labels are translated to
 */
final readonly class AcceptLanguage implements ProvidesSchema
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
            description: 'The preferred languages as in RFC 9110, labels are translated to the best matching one Neos has translations for, else to the default locale',
            examples: ['de-DE,de;q=0.9,en;q=0.8'],
        );
    }
}
