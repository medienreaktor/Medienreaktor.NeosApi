<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Neos\Domain\Model;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * What the account may do in a workspace, from its workspace roles
 */
final readonly class WorkspacePermissions implements ProvidesSchema
{
    /**
     * @param bool $read whether it may read the workspace's content
     * @param bool $write whether it may change the content and publish or discard the changes
     * @param bool $manage whether it may change the workspace's title, description and roles and delete it
     */
    public function __construct(
        public bool $read,
        public bool $write,
        public bool $manage,
    ) {
    }

    public static function from(Model\WorkspacePermissions $permissions): self
    {
        return new self($permissions->read, $permissions->write, $permissions->manage);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
