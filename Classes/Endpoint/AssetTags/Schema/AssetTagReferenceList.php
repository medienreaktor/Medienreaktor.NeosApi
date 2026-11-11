<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\AssetTags\Schema;

use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Media\Domain\Model;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * The tags of an asset or an asset collection, sorted by label
 *
 * @implements \IteratorAggregate<AssetTagReference>
 */
final readonly class AssetTagReferenceList implements ProvidesSchema, \IteratorAggregate
{
    /**
     * @var list<AssetTagReference>
     */
    public array $assetTags;

    public function __construct(AssetTagReference ...$assetTags)
    {
        $this->assetTags = array_values($assetTags);
    }

    /**
     * @param iterable<Model\Tag> $tags
     */
    public static function of(iterable $tags, PersistenceManagerInterface $persistenceManager): self
    {
        $tags = [...$tags];
        usort($tags, static fn (Model\Tag $a, Model\Tag $b) => strcasecmp($a->getLabel(), $b->getLabel()));
        return new self(...array_map(
            static fn (Model\Tag $tag) => AssetTagReference::from($tag, $persistenceManager),
            $tags,
        ));
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }

    public function getIterator(): \Traversable
    {
        yield from $this->assetTags;
    }
}
