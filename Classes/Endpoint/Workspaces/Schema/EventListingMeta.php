<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\Schema;

use Neos\JsonSchema\Nullable;
use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\Support\ObjectProperties;

/**
 * What a page of a workspace's events says about the content stream they are from, JSON:API's meta
 *
 * No total: the event store can't count the events without reading them all
 */
final readonly class EventListingMeta implements ProvidesSchema
{
    public function __construct(
        public ContentStreamId $contentStreamId,
        public ContentStreamPosition|null $forkedFrom,
    ) {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= ObjectSchema::create(
            description: 'The content stream the events are from. contentStreamId is the workspace\'s current one, whose events are listed: a different one than on an earlier page means the workspace was published, discarded or rebased in between, and the events listed before are no longer its own. forkedFrom is where it branched off its base workspace\'s content stream (the version of the base\'s event it starts after), null for one that wasn\'t forked, e.g. live\'s',
            properties: ObjectProperties::create(
                contentStreamId: ContentStreamId::schema(),
                forkedFrom: Nullable::wrap(ContentStreamPosition::schema()),
            ),
            additionalProperties: false,
            required: ['contentStreamId', 'forkedFrom'],
        );
    }
}
