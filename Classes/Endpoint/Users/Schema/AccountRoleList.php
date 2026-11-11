<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Users\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A list of roles that can be assigned to an account
 *
 * @implements \IteratorAggregate<AccountRole>
 */
final readonly class AccountRoleList implements ProvidesSchema, \IteratorAggregate
{
    /**
     * @var list<AccountRole>
     */
    public array $roles;

    public function __construct(AccountRole ...$roles)
    {
        $this->roles = array_values($roles);
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
