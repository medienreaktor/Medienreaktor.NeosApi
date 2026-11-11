<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\Schema;

use Neos\ContentRepository\Core\Feature\WorkspaceRebase\Dto\RebaseErrorHandlingStrategy;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;

/**
 * What a rebase does with changes that conflict with the base workspace, the values of the content repository's
 * RebaseErrorHandlingStrategy
 */
enum RebaseStrategy: string implements ProvidesSchema
{
    case FAIL = 'fail';
    case FORCE = 'force';

    public function toRebaseErrorHandlingStrategy(): RebaseErrorHandlingStrategy
    {
        return match ($this) {
            self::FAIL => RebaseErrorHandlingStrategy::STRATEGY_FAIL,
            self::FORCE => RebaseErrorHandlingStrategy::STRATEGY_FORCE,
        };
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= StringSchema::create(
            description: 'fail (conflicting changes fail the rebase, nothing is changed) or force (conflicting changes are dropped, the others rebased)',
            enum: array_map(static fn (self $case) => $case->value, self::cases()),
        );
    }
}
