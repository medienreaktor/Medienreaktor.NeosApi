<?php

declare(strict_types=1);

namespace Neos\Api\Shared\Schema;

use Neos\Api\Shared\Parameter\Limit;
use Neos\JsonSchema\Nullable;
use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\JsonSchema\Support\ObjectProperties;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The pages of a list paged with a cursor instead of an offset: the request's URL with ?after= the last item of a page
 *
 * For lists that can only be read from a position on, not counted, so there are no first, prev and last links
 */
final readonly class CursorListingLinks implements ProvidesSchema
{
    public function __construct(
        public string $self,
        public string|null $next,
    ) {
    }

    /**
     * For a list read towards its end, which ends with the last item: next is null on the last page
     *
     * @param int|null $nextAfter the cursor of the last item of this page if there are more, else null
     */
    public static function for(ServerRequestInterface $request, Limit $limit, int|null $nextAfter): self
    {
        return new self(self::with($request, $limit, []), $nextAfter !== null ? self::with($request, $limit, ['after' => $nextAfter]) : null);
    }

    /**
     * For a list that grows at its end and is polled for new items (a workspace's events, oldest first): next is
     * always there, after the last item of this page, or with the same cursor as this page if it is empty (without
     * one if there was none), so a poller can always follow it
     *
     * @param int|null $lastAfter the cursor of the last item of this page, else the one this page was read after
     */
    public static function forGrowingList(ServerRequestInterface $request, Limit $limit, int|null $lastAfter): self
    {
        return new self(self::with($request, $limit, []), self::with($request, $limit, ['after' => $lastAfter]));
    }

    /**
     * The request's URL with the limit and the changes, a null change removes the parameter
     *
     * @param array<string, int|null> $changes
     */
    private static function with(ServerRequestInterface $request, Limit $limit, array $changes): string
    {
        $query = array_filter(
            array_replace($request->getQueryParams(), ['limit' => $limit->value], $changes),
            static fn (mixed $value) => $value !== null,
        );
        return (string)$request->getUri()->withQuery(http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }

    public static function schema(): Schema
    {
        static $schema = null;
        $link = static fn (string $description) => StringSchema::create(description: $description, examples: ['https://example.com/api/cr/default/workspaces/live/events?after=1234&limit=25']);
        return $schema ??= ObjectSchema::create(
            description: 'The URLs of this page and the next, the request with another cursor',
            properties: ObjectProperties::create(
                self: $link('This page'),
                next: Nullable::wrap($link('The page after. Null on the last one, except for a list that is polled for new items (as a workspace\'s events oldest first), whose next page is always there, empty until there are new ones')),
            ),
            additionalProperties: false,
            required: ['self', 'next'],
        );
    }
}
