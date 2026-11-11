<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Users\Schema;

use Neos\Api\Shared\Schema\PackageKey;
use Neos\Flow\Security\Policy\Role;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A Flow security role that can be assigned to an account
 */
final readonly class AccountRole implements ProvidesSchema
{
    /**
     * @param string $label the label of the role, its name if it has none
     * @param string|null $description the description of the role, if it has one
     * @param PackageKey $packageKey the package that defines the role
     */
    public function __construct(
        public RoleIdentifier $identifier,
        public string $label,
        public string|null $description,
        public PackageKey $packageKey,
    ) {
    }

    public static function from(Role $role): self
    {
        return new self(
            RoleIdentifier::fromString($role->getIdentifier()),
            $role->getLabel(),
            $role->getDescription() !== '' ? $role->getDescription() : null,
            PackageKey::fromString($role->getPackageKey()),
        );
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
