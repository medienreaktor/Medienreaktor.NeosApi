<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\ContentRepositories\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * The content dimensions of a content repository
 *
 * @implements \IteratorAggregate<ContentDimension>
 */
final readonly class ContentDimensionList implements ProvidesSchema, \IteratorAggregate
{
    /**
     * @var list<ContentDimension>
     */
    public array $dimensions;

    public function __construct(ContentDimension ...$dimensions)
    {
        $this->dimensions = array_values($dimensions);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }

    public function getIterator(): \Traversable
    {
        yield from $this->dimensions;
    }
}
