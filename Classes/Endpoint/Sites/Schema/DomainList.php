<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Sites\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * The domains of a site
 *
 * @implements \IteratorAggregate<Domain>
 */
final readonly class DomainList implements ProvidesSchema, \IteratorAggregate
{
    /**
     * @var list<Domain>
     */
    public array $domains;

    public function __construct(Domain ...$domains)
    {
        $this->domains = array_values($domains);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }

    public function getIterator(): \Traversable
    {
        yield from $this->domains;
    }
}
