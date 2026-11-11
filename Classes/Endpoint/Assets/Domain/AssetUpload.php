<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Assets\Domain;

use Doctrine\Common\Collections\ArrayCollection;
use Neos\Api\Endpoint\AssetCollections\Domain\AssetCollectionResolver;
use Neos\Api\Endpoint\AssetCollections\Schema\AssetCollectionId;
use Neos\Api\Endpoint\Assets\Schema\AssetTitle;
use Neos\Api\Endpoint\AssetTags\Domain\AssetTagResolver;
use Neos\Api\Endpoint\AssetTags\Schema\AssetTagId;
use Neos\Api\Infrastructure\Http\MultipartForm;
use Neos\Api\Shared\Response\BadRequest;
use Neos\Api\Shared\Response\UnprocessableContent;
use Neos\Media\Domain\Model;
use Neos\Schematic\Exception\InstantiationFailed;
use Psr\Http\Message\UploadedFileInterface;

/**
 * The multipart form of createAsset (it has no request body class, see MultipartForm): file, title, collectionIds[]
 * and tagIds[], the tags and collections looked up
 */
final readonly class AssetUpload
{
    /**
     * @param list<Model\Tag> $tags
     * @param list<Model\AssetCollection> $collections
     */
    private function __construct(
        public UploadedFileInterface $file,
        private ?AssetTitle $title,
        private array $tags,
        private array $collections,
    ) {
    }

    /**
     * The upload, a 400 for a missing file or a malformed field, a 422 for an unknown tag or collection
     */
    public static function fromForm(MultipartForm $form, AssetTagResolver $assetTagResolver, AssetCollectionResolver $assetCollectionResolver): self|BadRequest|UnprocessableContent
    {
        $file = $form->file('file');
        if ($file === null) {
            return BadRequest::because($form->uploadError('file'));
        }
        try {
            $title = $form->string('title');
            $tagIds = $form->strings('tagIds');
            $collectionIds = $form->strings('collectionIds');
        } catch (\UnexpectedValueException $exception) {
            return BadRequest::because($exception->getMessage());
        }
        try {
            $title = $title !== null ? AssetTitle::fromString($title) : null;
        } catch (InstantiationFailed) {
            return BadRequest::because(sprintf('title must have at most %d characters', AssetTitle::MAX_LENGTH));
        }
        try {
            $tagIds = array_map(AssetTagId::fromString(...), $tagIds);
            $collectionIds = array_map(AssetCollectionId::fromString(...), $collectionIds);
        } catch (InstantiationFailed) {
            return BadRequest::because('tagIds[] must be IDs of asset tags, collectionIds[] IDs of asset collections');
        }
        $tags = $assetTagResolver->resolveReferences($tagIds);
        if ($tags instanceof UnprocessableContent) {
            return $tags;
        }
        $collections = $assetCollectionResolver->resolveReferences($collectionIds);
        if ($collections instanceof UnprocessableContent) {
            return $collections;
        }
        return new self($file, $title, $tags, $collections);
    }

    /**
     * Applies title, tags and collections to the new asset
     */
    public function applyTo(Model\Asset $asset): void
    {
        if ($this->title !== null) {
            $asset->setTitle($this->title->value);
        }
        foreach ($this->tags as $tag) {
            $asset->addTag($tag);
        }
        if ($this->collections !== []) {
            $asset->setAssetCollections(new ArrayCollection($this->collections));
        }
    }
}
