<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\DataSources\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A list of data sources
 *
 * @implements \IteratorAggregate<DataSource>
 */
final readonly class DataSourceList implements ProvidesSchema, \IteratorAggregate
{
    /**
     * @var list<DataSource>
     */
    public array $dataSources;

    public function __construct(DataSource ...$dataSources)
    {
        $this->dataSources = array_values($dataSources);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }

    public function getIterator(): \Traversable
    {
        yield from $this->dataSources;
    }
}
