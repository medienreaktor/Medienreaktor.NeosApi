<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Users\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * The roles assigned to an account
 *
 * @implements \IteratorAggregate<RoleIdentifier>
 */
final readonly class RoleIdentifierList implements ProvidesSchema, \IteratorAggregate
{
    /**
     * @var list<RoleIdentifier>
     */
    public array $roles;

    public function __construct(RoleIdentifier ...$roles)
    {
        $this->roles = array_values($roles);
    }

    /**
     * @return list<string>
     */
    public function identifiers(): array
    {
        return array_map(static fn (RoleIdentifier $role) => $role->value, $this->roles);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }

    public function getIterator(): \Traversable
    {
        yield from $this->roles;
    }
}
