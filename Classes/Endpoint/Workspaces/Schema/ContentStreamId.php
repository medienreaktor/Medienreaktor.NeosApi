<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\Schema;

use Neos\ContentRepository\Core\SharedModel;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;

final readonly class ContentStreamId implements ProvidesSchema
{
    private function __construct(
        public string $value,
    ) {
    }

    public static function from(SharedModel\Workspace\ContentStreamId $contentStreamId): self
    {
        return new self($contentStreamId->value);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= StringSchema::create(
            description: 'The id of a content stream, the events a workspace\'s content is made of. A workspace gets a new one when it is created, published, discarded or rebased',
            examples: ['8b2a3c1e-5d4f-4e6a-9b7c-0d1e2f3a4b5c'],
        );
    }
}
