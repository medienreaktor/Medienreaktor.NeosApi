<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\AssetTags\Schema;

use Neos\JsonSchema\IntegerSchema;
use Neos\JsonSchema\Nullable;
use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\Support\ObjectProperties;

/**
 * A tag of the media library with its child tags, tags form a tree
 *
 * children contains AssetTag itself, see AssetTagList: so the schema is written out. AssetTagTree makes it, from all
 * tags at once
 */
final readonly class AssetTag implements ProvidesSchema
{
    /**
     * @param AssetTagId|null $parentId the parent tag, null for a tag at the root
     * @param int $assetCount how many assets have the tag
     * @param AssetTagList $children typed iterable, not AssetTagList, see there
     */
    public function __construct(
        public AssetTagId $id,
        public string $label,
        public AssetTagId|null $parentId,
        public int $assetCount,
        public iterable $children,
    ) {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= ObjectSchema::create(
            description: 'A tag of the media library with its child tags',
            properties: ObjectProperties::create(
                id: AssetTagId::schema(),
                label: AssetTagLabel::schema(),
                parentId: Nullable::wrap(AssetTagId::schema()),
                assetCount: IntegerSchema::create(description: 'How many assets have the tag, of the ones the account may see', minimum: 0),
                // the very schema of AssetTagList, see NodeList
                children: AssetTagList::schema(),
            ),
            additionalProperties: false,
            required: ['id', 'label', 'parentId', 'assetCount', 'children'],
        );
    }
}
