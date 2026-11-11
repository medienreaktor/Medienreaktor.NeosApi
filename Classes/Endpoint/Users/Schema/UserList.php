<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Users\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A list of Neos users
 *
 * @implements \IteratorAggregate<User>
 */
final readonly class UserList implements ProvidesSchema, \IteratorAggregate
{
    /**
     * @var list<User>
     */
    public array $users;

    public function __construct(User ...$users)
    {
        $this->users = array_values($users);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }

    public function getIterator(): \Traversable
    {
        yield from $this->users;
    }
}
