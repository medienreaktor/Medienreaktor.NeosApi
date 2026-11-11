<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Sites\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;

/**
 * The scheme of a domain
 */
enum UriScheme: string implements ProvidesSchema
{
    case HTTP = 'http';
    case HTTPS = 'https';

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= StringSchema::create(
            description: 'The scheme of a domain',
            enum: array_column(self::cases(), 'value'),
        );
    }
}
