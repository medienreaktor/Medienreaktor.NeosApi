<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\ContentRepositories\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A list of content repositories
 *
 * @implements \IteratorAggregate<ContentRepository>
 */
final readonly class ContentRepositoryList implements ProvidesSchema, \IteratorAggregate
{
    /**
     * @var list<ContentRepository>
     */
    public array $contentRepositories;

    public function __construct(ContentRepository ...$contentRepositories)
    {
        $this->contentRepositories = array_values($contentRepositories);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }

    public function getIterator(): \Traversable
    {
        yield from $this->contentRepositories;
    }
}
