<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Me\RequestBody;

use Neos\Api\Endpoint\Users\Schema\Password;
use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\JsonSchema\Support\ObjectProperties;

/**
 * A new password, with the current one to confirm it is the user who changes it
 *
 * The schema is written out, currentPassword is a plain string: it is checked, not validated
 */
final readonly class PasswordChange implements ProvidesSchema
{
    public function __construct(
        public string $currentPassword,
        public Password $newPassword,
    ) {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= ObjectSchema::create(
            description: 'A new password, with the current one to confirm it is the user who changes it',
            properties: ObjectProperties::create(
                currentPassword: StringSchema::create(description: 'The password the user logs in with now', writeOnly: true),
                newPassword: Password::schema(),
            ),
            additionalProperties: false,
            required: ['currentPassword', 'newPassword'],
        );
    }
}
