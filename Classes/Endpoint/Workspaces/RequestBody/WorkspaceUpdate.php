<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\RequestBody;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Neos\Domain\Model;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * Changes to a workspace's Neos metadata: what is left out or null stays as it is
 *
 * There is no applyTo(): the metadata has no model to change, the endpoint sets it with Neos' WorkspaceService
 */
final readonly class WorkspaceUpdate implements ProvidesSchema
{
    /**
     * @param string|null $title the title Neos shows, trimmed, 1 to 200 letters, digits, spaces and punctuation
     * @param string|null $description up to 500 letters, digits, spaces and punctuation, empty to remove it
     */
    public function __construct(
        public string|null $title = null,
        public string|null $description = null,
    ) {
    }

    /**
     * The new title, null if it stays as it is
     *
     * @throws \InvalidArgumentException if it's empty or has characters Neos doesn't allow
     */
    public function newTitle(): ?Model\WorkspaceTitle
    {
        return $this->title !== null ? Model\WorkspaceTitle::fromString(trim($this->title)) : null;
    }

    /**
     * The new description, null if it stays as it is
     *
     * @throws \InvalidArgumentException if it's too long or has characters Neos doesn't allow
     */
    public function newDescription(): ?Model\WorkspaceDescription
    {
        return $this->description !== null ? Model\WorkspaceDescription::fromString($this->description) : null;
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
