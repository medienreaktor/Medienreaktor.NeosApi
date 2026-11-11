<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Assets\Schema;

use Neos\Api\Endpoint\Nodes\Schema\Node;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A node that uses an asset in one of its properties
 */
final readonly class AssetUsage implements ProvidesSchema
{
    /**
     * @param Node $node the node, read in the workspace and dimension space point it uses the asset in, as getNode returns it without include
     * @param Node|null $documentNode the document the node is in (the node itself for a document), null if there is none the account may read
     */
    public function __construct(
        public Node $node,
        public Node|null $documentNode,
    ) {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
