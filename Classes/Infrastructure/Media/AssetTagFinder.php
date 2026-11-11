<?php

declare(strict_types=1);

namespace Neos\Api\Infrastructure\Media;

use Neos\Flow\Annotations as Flow;
use Neos\Media\Domain\Model;
use Neos\Media\Domain\Repository\TagRepository;

/**
 * Finds the tags of the media library as the account may see them (Neos' media privileges, Doctrine filters) and
 * counts their assets
 */
#[Flow\Scope('singleton')]
final readonly class AssetTagFinder
{
    public function __construct(
        private TagRepository $tagRepository,
        private AssetCounter $assetCounter,
    ) {
    }

    /**
     * The tag, null if there is none with the ID the account may see
     */
    public function find(string $assetTagId): ?Model\Tag
    {
        $tag = $this->tagRepository->findByIdentifier($assetTagId);
        return $tag instanceof Model\Tag ? $tag : null;
    }

    /**
     * @return list<Model\Tag>
     */
    public function findAll(): array
    {
        /** @var list<Model\Tag> $tags */
        $tags = $this->tagRepository->findAll()->toArray();
        return $tags;
    }

    /**
     * How many assets have each tag, by tag ID, see AssetCounter
     *
     * @return array<string, int>
     */
    public function assetCounts(): array
    {
        return $this->assetCounter->countBy('tags');
    }
}
