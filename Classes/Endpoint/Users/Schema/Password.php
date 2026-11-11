<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Users\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\Schematic\Schematic;

/**
 * The password of a new account, with the limits of the Neos user module
 */
final readonly class Password implements ProvidesSchema
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
            description: 'The password to log in with',
            writeOnly: true,
            minLength: 1,
            maxLength: 255,
        );
    }
}
