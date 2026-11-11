<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Nodes\Schema;

use Neos\JsonSchema\Nullable;
use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\JsonSchema\Support\ObjectProperties;

/**
 * When a node was created and last modified, as ISO 8601, see NodeSerializer::timestamps()
 */
final readonly class NodeTimestamps implements ProvidesSchema
{
    public function __construct(
        public string $created,
        public string|null $lastModified,
        public string $originalCreated,
        public string|null $originalLastModified,
    ) {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        $timestamp = static fn (string $description) => StringSchema::create(description: $description, examples: ['2026-10-01T09:30:00+00:00']);
        return $schema ??= ObjectSchema::create(
            description: 'When the node was created and last modified, as ISO 8601',
            properties: ObjectProperties::create(
                created: $timestamp('When the node was created in its workspace'),
                lastModified: Nullable::wrap($timestamp('When the node was last modified in its workspace, null if never')),
                originalCreated: $timestamp('When the node was created in the live workspace'),
                originalLastModified: Nullable::wrap($timestamp('When the node was last modified in the live workspace, null if never')),
            ),
            additionalProperties: false,
            required: ['created', 'lastModified', 'originalCreated', 'originalLastModified'],
        );
    }
}
