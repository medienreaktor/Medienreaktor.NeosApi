<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Nodes\RequestBody;

use Neos\JsonSchema\Nullable;
use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\JsonSchema\Support\ObjectProperties;

/**
 * What a document's uriPathSegment is generated from
 *
 * There is no applyTo(): the endpoint generates the segment and sets it as a property. The schema is written out: with
 * a single string parameter schematic would take the class for that string
 */
final readonly class NodeUriPathSegmentGenerate implements ProvidesSchema
{
    public function __construct(
        public string|null $text = null,
    ) {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= ObjectSchema::create(
            description: 'What to generate the uriPathSegment from, {} for the node\'s title',
            properties: ObjectProperties::create(
                text: Nullable::wrap(StringSchema::create(
                    description: 'The text to generate it from, e.g. a title not saved yet. If left out, the node\'s title',
                    examples: ['Über uns'],
                )),
            ),
            additionalProperties: false,
        );
    }
}
