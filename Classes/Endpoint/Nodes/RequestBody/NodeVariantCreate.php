<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Nodes\RequestBody;

use Neos\Api\Endpoint\Nodes\Schema\DimensionSpacePoint;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A variant of a node to create in another dimension space point
 *
 * There is no applyTo(): a variant is created by commands, which the endpoint builds
 */
final readonly class NodeVariantCreate implements ProvidesSchema
{
    /**
     * @param DimensionSpacePoint $dimensionSpacePoint the dimension space point to create the variant in, as JSON as everywhere
     * @param bool|null $copyContent also create variants of the content below the node (not of documents), as the Neos backend does when translating a document. false if left out
     */
    public function __construct(
        public DimensionSpacePoint $dimensionSpacePoint,
        public bool|null $copyContent = null,
    ) {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
