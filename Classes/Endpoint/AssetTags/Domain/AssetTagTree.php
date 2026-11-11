<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\AssetTags\Domain;

use Neos\Api\Endpoint\AssetTags\Schema\AssetTag;
use Neos\Api\Endpoint\AssetTags\Schema\AssetTagId;
use Neos\Api\Endpoint\AssetTags\Schema\AssetTagList;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Media\Domain\Model;

/**
 * The tags the account may see, by parent, with their asset counts: AssetTag is made from it without a query per tag
 */
final readonly class AssetTagTree
{
    /**
     * @var array<string, list<Model\Tag>> by parent tag ID, '' for the root
     */
    private array $children;

    /**
     * @param list<Model\Tag> $tags
     * @param array<string, int> $assetCounts by tag ID
     */
    public function __construct(
        private array $tags,
        private array $assetCounts,
        private PersistenceManagerInterface $persistenceManager,
    ) {
        $children = [];
        foreach ($tags as $tag) {
            $parent = $tag->getParent();
            $children[$parent !== null ? $this->persistenceManager->getIdentifierByObject($parent) : ''][] = $tag;
        }
        foreach ($children as &$siblings) {
            usort($siblings, static fn (Model\Tag $a, Model\Tag $b) => strcasecmp($a->getLabel(), $b->getLabel()));
        }
        $this->children = $children;
    }

    public function count(): int
    {
        return count($this->tags);
    }

    public function roots(): AssetTagList
    {
        return $this->list($this->children[''] ?? []);
    }

    public function assetTag(Model\Tag $tag): AssetTag
    {
        $id = $this->id($tag);
        $parent = $tag->getParent();
        return new AssetTag(
            $id,
            $tag->getLabel(),
            $parent !== null ? $this->id($parent) : null,
            $this->assetCounts[$id->value] ?? 0,
            $this->list($this->children[$id->value] ?? []),
        );
    }

    /**
     * @param list<Model\Tag> $tags
     */
    private function list(array $tags): AssetTagList
    {
        return new AssetTagList(...array_map($this->assetTag(...), $tags));
    }

    private function id(Model\Tag $tag): AssetTagId
    {
        return AssetTagId::fromString($this->persistenceManager->getIdentifierByObject($tag));
    }
}
