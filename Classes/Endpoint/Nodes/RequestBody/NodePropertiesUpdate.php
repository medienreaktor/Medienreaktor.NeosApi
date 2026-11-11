<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Nodes\RequestBody;

use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;

/**
 * Changes to the properties of a node, the properties by name: the ones to set, what is left out stays as it is
 *
 * Only describes and validates the body, as Shared\Parameter\Arguments the query: Schematic builds an object from its named
 * constructor parameters, which a free-form map has none of, so the operation reads the values from the body itself.
 * There is no applyTo(): a node is changed by a command, which PropertyValues builds the values for
 */
final readonly class NodePropertiesUpdate implements ProvidesSchema
{
    private function __construct()
    {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= ObjectSchema::create(
            description: 'The properties to set by name, in the form Node has them: e.g. a date as RFC 3339 string, an asset (also in a list) as {"id": "…"}. null unsets a property, the ones left out stay as they are. Properties of other types than string, integer, float, boolean, array, dates and assets can\'t be written yet',
            examples: [['title' => 'Home', 'image' => ['id' => 'a3474e1d-dd60-4a84-82b1-18d2f21891a3'], 'teaser' => null]],
            additionalProperties: true,
        );
    }
}
