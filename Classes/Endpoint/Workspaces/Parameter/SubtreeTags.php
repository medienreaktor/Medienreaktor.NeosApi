<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\Parameter;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\Schematic\Schematic;

/**
 * The tags of filterByTag, comma-separated, as Nodes\Schema\SubtreeTag
 */
final readonly class SubtreeTags implements ProvidesSchema
{
    /**
     * The rules of Nodes\Schema\SubtreeTag
     */
    private const string TAG = '[a-z0-9_.-]{1,36}';

    private function __construct(
        public string $value,
    ) {
    }

    public static function fromString(string $value): self
    {
        return Schematic::instantiate(self::class, $value)->valueOrThrow();
    }

    /**
     * Whether the tag is one of them
     */
    public function contains(string $tag): bool
    {
        return in_array($tag, explode(',', $this->value), true);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= StringSchema::create(
            description: 'Tags of nodes, comma-separated, e.g. removed for deleted nodes, disabled for hidden ones',
            examples: ['removed'],
            pattern: '^' . self::TAG . '(,' . self::TAG . ')*$',
        );
    }
}
