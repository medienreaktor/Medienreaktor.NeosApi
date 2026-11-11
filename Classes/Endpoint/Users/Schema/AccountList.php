<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Users\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * The accounts of a Neos user
 *
 * @implements \IteratorAggregate<Account>
 */
final readonly class AccountList implements ProvidesSchema, \IteratorAggregate
{
    /**
     * @var list<Account>
     */
    public array $accounts;

    public function __construct(Account ...$accounts)
    {
        $this->accounts = array_values($accounts);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }

    public function getIterator(): \Traversable
    {
        yield from $this->accounts;
    }
}
