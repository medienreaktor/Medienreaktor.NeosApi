<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Nodes\RequestBody;

use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;

/**
 * Changes to the references of a node, by reference name: the ones to replace, what is left out stays as it is
 *
 * Only describes and validates the body, as NodePropertiesUpdate: Schematic builds an object from its named
 * constructor parameters, which a free-form map has none of, so the operation reads the values from the body itself.
 * Its schema can't describe the lists either (neos/jsonschema's additionalProperties is a bool only), PropertyValues
 * checks them
 */
final readonly class NodeReferencesUpdate implements ProvidesSchema
{
    private function __construct()
    {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= ObjectSchema::create(
            description: 'The references to set by name, each a list in the form Node has them: [{"nodeAggregateId": "…", "properties": {…}}], properties optional and in the form of node properties. A list replaces all references of that name, in its order, an empty list removes them, the names left out stay as they are',
            examples: [['relatedPages' => [['nodeAggregateId' => 'a3474e1d-dd60-4a84-82b1-18d2f21891a3'], ['nodeAggregateId' => 'd87adae6-9e61-4dbc-b596-219abe1e45a2']]]],
            additionalProperties: true,
        );
    }
}
