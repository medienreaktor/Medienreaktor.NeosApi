<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\People\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A list of people
 *
 * @implements \IteratorAggregate<Person>
 */
final readonly class PersonList implements ProvidesSchema, \IteratorAggregate
{
    /**
     * @var list<Person>
     */
    public array $people;

    public function __construct(Person ...$people)
    {
        $this->people = array_values($people);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }

    public function getIterator(): \Traversable
    {
        yield from $this->people;
    }
}
