<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\RequestBody;

use Neos\Api\Endpoint\Workspaces\Schema\RebaseStrategy;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * How to rebase a workspace
 *
 * There is no applyTo(): the workspace is rebased by a command, which the endpoint sends through Neos' service
 */
final readonly class WorkspaceRebase implements ProvidesSchema
{
    /**
     * @param RebaseStrategy|null $strategy what to do with changes that conflict with the base workspace, fail if left out
     */
    public function __construct(
        public RebaseStrategy|null $strategy = null,
    ) {
    }

    public function strategy(): RebaseStrategy
    {
        return $this->strategy ?? RebaseStrategy::FAIL;
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
