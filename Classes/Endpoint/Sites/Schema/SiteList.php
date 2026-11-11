<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Sites\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A list of Neos sites
 *
 * @implements \IteratorAggregate<Site>
 */
final readonly class SiteList implements ProvidesSchema, \IteratorAggregate
{
    /**
     * @var list<Site>
     */
    public array $sites;

    public function __construct(Site ...$sites)
    {
        $this->sites = array_values($sites);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }

    public function getIterator(): \Traversable
    {
        yield from $this->sites;
    }
}
