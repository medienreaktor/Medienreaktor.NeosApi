<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Views\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A list of views
 *
 * @implements \IteratorAggregate<View>
 */
final readonly class ViewList implements ProvidesSchema, \IteratorAggregate
{
    /**
     * @var list<View>
     */
    public array $views;

    public function __construct(View ...$views)
    {
        $this->views = array_values($views);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }

    public function getIterator(): \Traversable
    {
        yield from $this->views;
    }
}
