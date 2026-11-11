<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Nodes\Schema;

use Neos\Api\Shared\Schema\ListingLinks;
use Neos\Api\Shared\Schema\ListingMeta;
use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\Support\ObjectProperties;

/**
 * A page of nodes, as JSON:API's top level: the nodes in data, the total in meta, the other pages in links
 *
 * The schema is written out and data typed iterable, as Node's children, see NodeList
 */
final readonly class PaginatedNodeListing implements ProvidesSchema
{
    /**
     * @param NodeList $data typed iterable, not NodeList, see there
     */
    public function __construct(
        public iterable $data,
        public ListingMeta $meta,
        public ListingLinks $links,
    ) {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= ObjectSchema::create(
            description: 'A page of nodes with the total and the links to the other pages',
            properties: ObjectProperties::create(
                data: NodeList::schema(),
                meta: ListingMeta::schema(),
                links: ListingLinks::schema(),
            ),
            additionalProperties: false,
            required: ['data', 'meta', 'links'],
        );
    }
}
