<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\Schema;

use Neos\ContentRepository\Core\Feature\ContentStreamForking\Event\ContentStreamWasForked;
use Neos\JsonSchema\IntegerSchema;
use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\Support\ObjectProperties;

/**
 * A point in a content stream: the stream and the version of its event
 */
final readonly class ContentStreamPosition implements ProvidesSchema
{
    public function __construct(
        public ContentStreamId $contentStreamId,
        public int $version,
    ) {
    }

    /**
     * Where a forked content stream branched off its source
     */
    public static function from(ContentStreamWasForked $event): self
    {
        return new self(ContentStreamId::from($event->sourceContentStreamId), $event->versionOfSourceContentStream->value);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= ObjectSchema::create(
            description: 'A point in a content stream: the stream and the version of its event',
            properties: ObjectProperties::create(
                contentStreamId: ContentStreamId::schema(),
                version: IntegerSchema::create(description: 'The version of the event in the content stream (an event\'s version), from 0', minimum: 0),
            ),
            additionalProperties: false,
            required: ['contentStreamId', 'version'],
        );
    }
}
