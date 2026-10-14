<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Nodes\Schema;

use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\Support\ObjectProperties;

/**
 * The variants of a node, all of them, as JSON:API's top level: the variants in data, the total and the dimension
 * space points the node covers in meta
 *
 * The schema is written out and data typed iterable, as Node's children, see NodeList
 */
final readonly class NodeVariantListing implements ProvidesSchema
{
    /**
     * @param NodeList $data typed iterable, not NodeList, see there
     */
    public function __construct(
        public iterable $data,
        public NodeVariantListingMeta $meta,
    ) {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= ObjectSchema::create(
            description: 'The variants of a node with their total and the dimension space points the node covers',
            properties: ObjectProperties::create(
                data: NodeList::schema(),
                meta: NodeVariantListingMeta::schema(),
            ),
            additionalProperties: false,
            required: ['data', 'meta'],
        );
    }
}
