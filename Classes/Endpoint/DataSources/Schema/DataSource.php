<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\DataSources\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A data source: PHP code that provides data, e.g. the options of a select box in the Neos UI's inspector
 */
final readonly class DataSource implements ProvidesSchema
{
    public function __construct(
        public DataSourceId $id,
    ) {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
