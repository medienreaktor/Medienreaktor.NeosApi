<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\RequestBody;

use Neos\Api\Endpoint\Workspaces\Schema\WorkspaceName;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * The workspace a workspace is to be based on from now on
 *
 * There is no applyTo(): the base is changed by a command, which the endpoint builds
 */
final readonly class BaseWorkspaceChange implements ProvidesSchema
{
    /**
     * @param WorkspaceName $baseWorkspaceName the new base workspace, one the account may read
     */
    public function __construct(
        public WorkspaceName $baseWorkspaceName,
    ) {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
