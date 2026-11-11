<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Users\RequestBody;

use Neos\Api\Endpoint\Users\Schema\Password;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A new password for a user, set by someone who manages it: no current password needed
 *
 * No applyTo(), the password is set through the UserService by the caller
 */
final readonly class UserPasswordSet implements ProvidesSchema
{
    public function __construct(
        public Password $newPassword,
    ) {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
