<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Me\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * The scopes granted to an access token
 *
 * @implements \IteratorAggregate<Scope>
 */
final readonly class ScopeList implements ProvidesSchema, \IteratorAggregate
{
    /**
     * @var list<Scope>
     */
    public array $scopes;

    public function __construct(Scope ...$scopes)
    {
        $this->scopes = array_values($scopes);
    }

    public static function fromStrings(string ...$scopes): self
    {
        return new self(...array_map(Scope::fromString(...), $scopes));
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }

    public function getIterator(): \Traversable
    {
        yield from $this->scopes;
    }
}
