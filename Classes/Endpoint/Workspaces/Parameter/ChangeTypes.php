<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\Parameter;

use Neos\Api\Endpoint\Workspaces\Schema\Change;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\Schematic\Schematic;

/**
 * The kinds of change of filterByChangeType, comma-separated: created, changed, moved, deleted
 */
final readonly class ChangeTypes implements ProvidesSchema
{
    private const TYPES = '(created|changed|moved|deleted)';

    private function __construct(
        public string $value,
    ) {
    }

    public static function fromString(string $value): self
    {
        return Schematic::instantiate(self::class, $value)->valueOrThrow();
    }

    /**
     * Whether the change has one of the flags
     */
    public function matches(Change $change): bool
    {
        foreach (explode(',', $this->value) as $type) {
            if (
                match ($type) {
                'created' => $change->created,
                'changed' => $change->changed,
                'moved' => $change->moved,
                'deleted' => $change->deleted,
                default => false,
                }
            ) {
                return true;
            }
        }
        return false;
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= StringSchema::create(
            description: 'Kinds of change, comma-separated: created, changed, moved, deleted. A change matches if it has one of them',
            examples: ['created,changed'],
            pattern: '^' . self::TYPES . '(,' . self::TYPES . ')*$',
        );
    }
}
