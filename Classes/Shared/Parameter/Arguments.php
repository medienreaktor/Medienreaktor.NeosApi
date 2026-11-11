<?php

declare(strict_types=1);

namespace Neos\Api\Shared\Parameter;

use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Free-form arguments by name as the query parameter arguments (deepObject): ?arguments[name]=value&arguments[tags][]=a,
 * e.g. the ones a view is rendered with or a data source is called with. What they mean the operation's #[Parameter]
 * describes
 *
 * Only describes and validates the parameter: Schematic builds an object from its named constructor parameters, which a
 * free-form map has none of, so the operation reads the values from the query with values()
 */
final readonly class Arguments implements ProvidesSchema
{
    /**
     * The name of the query parameter, the operation's argument must be named so
     */
    public const string NAME = 'arguments';

    private function __construct()
    {
    }

    /**
     * The arguments of the request by name, none without any
     *
     * @return array<mixed>
     */
    public static function values(ServerRequestInterface $request): array
    {
        $values = $request->getQueryParams()[self::NAME] ?? [];
        return is_array($values) ? $values : [];
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= ObjectSchema::create(
            description: 'Arguments by name, arrays as arguments[name][]=value. Which ones are taken is up to the operation',
            additionalProperties: true,
        );
    }
}
