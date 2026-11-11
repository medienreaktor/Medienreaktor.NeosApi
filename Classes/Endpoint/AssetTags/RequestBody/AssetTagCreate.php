<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\AssetTags\RequestBody;

use Neos\Api\Endpoint\AssetTags\Schema\AssetTagId;
use Neos\Api\Endpoint\AssetTags\Schema\AssetTagLabel;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Media\Domain\Model;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * An asset tag to create
 */
final readonly class AssetTagCreate implements ProvidesSchema
{
    /**
     * @param AssetTagId|null $parentId the tag to create it in, at the root if left out
     */
    public function __construct(
        public AssetTagLabel $label,
        public AssetTagId|null $parentId = null,
    ) {
    }

    /**
     * Applies the parent, which the endpoint looked up: the label is the constructor's
     */
    public function applyTo(Model\Tag $tag, Model\Tag|null $parent): void
    {
        if ($parent !== null) {
            $tag->setParent($parent);
        }
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
