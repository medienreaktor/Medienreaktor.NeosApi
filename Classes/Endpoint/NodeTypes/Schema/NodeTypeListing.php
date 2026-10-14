<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\NodeTypes\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * The node types of a content repository, as JSON:API's top level: the items in data, the total and the groups in meta.
 * Not paged, so no links
 */
final readonly class NodeTypeListing implements ProvidesSchema
{
    public function __construct(
        public NodeTypeList $data,
        public NodeTypeListingMeta $meta,
    ) {
    }

    public static function of(NodeTypeList $data, NodeTypeGroupList $groups): self
    {
        return new self($data, new NodeTypeListingMeta(count($data->nodeTypes), $groups));
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
