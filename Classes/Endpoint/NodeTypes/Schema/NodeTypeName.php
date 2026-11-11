<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\NodeTypes\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\Schematic\Schematic;

final readonly class NodeTypeName implements ProvidesSchema
{
    private function __construct(
        public string $value,
    ) {
    }

    public static function fromString(string $value): self
    {
        return Schematic::instantiate(self::class, $value)->valueOrThrow();
    }

    /**
     * As the content repository: any name that isn't empty. The package prefix is a convention, a node type
     * configured without one (as Neos 8's unstructured) exists all the same
     *
     * @see \Neos\ContentRepository\Core\NodeType\NodeTypeName::__construct()
     * @link https://github.com/neos/neos-development-collection/blob/9.2.0/Neos.ContentRepository.Core/Classes/NodeType/NodeTypeName.php#L31-L37
     */
    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= StringSchema::create(
            description: 'The name of a content repository node type',
            examples: ['Neos.Demo:Document.Homepage'],
            minLength: 1,
        );
    }
}
