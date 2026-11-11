<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\AssetTags\Schema;

use Neos\JsonSchema\ArraySchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\OpenApi\Spec\SchemaObjectMap;
use Neos\Schematic\Serialization\Serializer;

/**
 * A list of asset tags: a tag's children or a listing's data
 *
 * AssetTag contains itself through this list, as Node through NodeList: the items are a $ref to the AssetTag
 * component, and the list hands the serializer the tags serialized already
 *
 * @implements \IteratorAggregate<array<string, mixed>>
 */
final readonly class AssetTagList implements ProvidesSchema, \IteratorAggregate
{
    /**
     * @var list<AssetTag>
     */
    public array $assetTags;

    public function __construct(AssetTag ...$assetTags)
    {
        $this->assetTags = array_values($assetTags);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= ArraySchema::create(
            description: 'Asset tags, sorted by label',
            items: SchemaObjectMap::reference('AssetTag'),
        );
    }

    /**
     * The tags serialized, see above
     */
    public function getIterator(): \Traversable
    {
        foreach ($this->assetTags as $assetTag) {
            yield Serializer::serialize($assetTag, AssetTag::schema());
        }
    }
}
