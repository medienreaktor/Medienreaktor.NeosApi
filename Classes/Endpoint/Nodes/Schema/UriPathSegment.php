<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Nodes\Schema;

use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\JsonSchema\Support\ObjectProperties;

/**
 * A URI path segment generated for a document, not set yet
 *
 * The schema is written out: with a single string parameter schematic would take the class for that string
 */
final readonly class UriPathSegment implements ProvidesSchema
{
    public function __construct(
        public string $uriPathSegment,
    ) {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= ObjectSchema::create(
            description: 'A URI path segment as generateNodeUriPathSegment would set it',
            properties: ObjectProperties::create(
                uriPathSegment: StringSchema::create(
                    description: 'Transliterated in the language of the dimension space point, lowercase, with dashes',
                    examples: ['ueber-uns'],
                    minLength: 1,
                ),
            ),
            additionalProperties: false,
            required: ['uriPathSegment'],
        );
    }
}
