<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\AssetTags\Domain;

use Neos\Api\Endpoint\AssetTags\Schema\AssetTagId;
use Neos\Api\Infrastructure\Media\AssetTagFinder;
use Neos\Api\Shared\Response\BadRequest;
use Neos\Api\Shared\Response\NotFound;
use Neos\Api\Shared\Response\UnprocessableContent;
use Neos\Flow\Annotations as Flow;
use Neos\Media\Domain\Model;

/**
 * Tag IDs of a request → the tags as the account may see them, or the error of where the ID came from: the 404 of the
 * path, the 422 of the body, the 400 of a filter. For every endpoint that refers to tags
 */
#[Flow\Scope('singleton')]
final readonly class AssetTagResolver
{
    public function __construct(
        private AssetTagFinder $assetTagFinder,
    ) {
    }

    /**
     * The tag of the path
     */
    public function resolve(AssetTagId $assetTagId): Model\Tag|NotFound
    {
        return $this->assetTagFinder->find($assetTagId->value) ?? NotFound::because(self::noTag($assetTagId));
    }

    /**
     * A tag the body refers to
     */
    public function resolveReference(AssetTagId $assetTagId): Model\Tag|UnprocessableContent
    {
        return $this->assetTagFinder->find($assetTagId->value) ?? UnprocessableContent::because(self::noTag($assetTagId));
    }

    /**
     * The tags the body refers to, each once, or the 422 for the first unknown one
     *
     * @param iterable<AssetTagId> $assetTagIds
     * @return list<Model\Tag>|UnprocessableContent
     */
    public function resolveReferences(iterable $assetTagIds): array|UnprocessableContent
    {
        $tags = [];
        foreach ($assetTagIds as $assetTagId) {
            $tag = $this->resolveReference($assetTagId);
            if ($tag instanceof UnprocessableContent) {
                return $tag;
            }
            $tags[spl_object_id($tag)] = $tag;
        }
        return array_values($tags);
    }

    /**
     * The tag a query parameter filters by
     */
    public function resolveFilter(AssetTagId $assetTagId): Model\Tag|BadRequest
    {
        return $this->assetTagFinder->find($assetTagId->value) ?? BadRequest::because(self::noTag($assetTagId));
    }

    private static function noTag(AssetTagId $assetTagId): string
    {
        return sprintf('There is no asset tag with the ID %s', $assetTagId->value);
    }
}
