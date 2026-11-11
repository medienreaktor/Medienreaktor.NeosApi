<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\ContentRepositories\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * The values of a content dimension
 *
 * @implements \IteratorAggregate<ContentDimensionValue>
 */
final readonly class ContentDimensionValueList implements ProvidesSchema, \IteratorAggregate
{
    /**
     * @var list<ContentDimensionValue>
     */
    public array $values;

    public function __construct(ContentDimensionValue ...$values)
    {
        $this->values = array_values($values);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }

    public function getIterator(): \Traversable
    {
        yield from $this->values;
    }
}
