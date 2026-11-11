<?php

declare(strict_types=1);

namespace Neos\Api\Shared\Schema;

use Neos\Api\Shared\Parameter\Limit;
use Neos\Api\Shared\Parameter\Offset;
use Neos\JsonSchema\Nullable;
use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\JsonSchema\Support\ObjectProperties;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The pages of a list, as JSON:API's pagination links: the request's URL with another offset
 */
final readonly class ListingLinks implements ProvidesSchema
{
    public function __construct(
        public string $self,
        public string $first,
        public string|null $prev,
        public string|null $next,
        public string $last,
    ) {
    }

    public static function for(ServerRequestInterface $request, Offset $offset, Limit $limit, int $total): self
    {
        $at = static function (int $at) use ($request, $limit): string {
            $query = $request->getQueryParams();
            $query['offset'] = $at;
            $query['limit'] = $limit->value;
            return (string)$request->getUri()->withQuery(http_build_query($query, '', '&', PHP_QUERY_RFC3986));
        };
        $lastOffset = $total > 0 ? intdiv($total - 1, $limit->value) * $limit->value : 0;
        return new self(
            $at($offset->value),
            $at(0),
            $offset->value > 0 ? $at(max(0, $offset->value - $limit->value)) : null,
            $offset->value + $limit->value < $total ? $at($offset->value + $limit->value) : null,
            $at($lastOffset),
        );
    }

    public static function schema(): Schema
    {
        static $schema = null;
        $link = static fn (string $description) => StringSchema::create(description: $description, examples: ['https://example.com/api/cr/default/nodes?offset=25&limit=25']);
        return $schema ??= ObjectSchema::create(
            description: 'The URLs of the pages, the request with another offset',
            properties: ObjectProperties::create(
                self: $link('This page'),
                first: $link('The first page'),
                prev: Nullable::wrap($link('The page before, null on the first one')),
                next: Nullable::wrap($link('The page after, null on the last one')),
                last: $link('The last page'),
            ),
            additionalProperties: false,
            required: ['self', 'first', 'prev', 'next', 'last'],
        );
    }
}
