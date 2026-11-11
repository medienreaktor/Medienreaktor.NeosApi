<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Views\Schema;

use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;

/**
 * The data of a rendered view, whatever its Fusion prototype builds
 *
 * Only describes the body of RenderedView, which is the decoded JSON as it is
 */
final readonly class ViewData implements ProvidesSchema
{
    private function __construct()
    {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= ObjectSchema::create(
            description: 'The data of the view, its shape is up to the view\'s Fusion prototype',
            additionalProperties: true,
        );
    }
}
