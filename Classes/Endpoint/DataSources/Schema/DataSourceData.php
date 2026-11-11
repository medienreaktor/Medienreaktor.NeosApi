<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\DataSources\Schema;

use Neos\JsonSchema\AnySchema;
use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\Support\ObjectProperties;

/**
 * The data a data source provided, in data so the top level is an object whatever it is
 *
 * Only describes the body of DataSourceResult, which is the decoded JSON as it is
 */
final readonly class DataSourceData implements ProvidesSchema
{
    private function __construct()
    {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= ObjectSchema::create(
            description: 'The data a data source provided',
            properties: ObjectProperties::create(
                data: AnySchema::create(description: 'Whatever the data source returns, for the Neos UI\'s select boxes by convention a list of {value, label} or an object of them by value'),
            ),
            additionalProperties: false,
            required: ['data'],
        );
    }
}
