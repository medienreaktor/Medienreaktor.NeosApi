<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Me\Schema;

use Neos\Api\Endpoint\Users\Schema\EmailAddress;
use Neos\JsonSchema\Nullable;
use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\JsonSchema\Support\ObjectProperties;
use Neos\Neos\Domain\Model;

/**
 * What the Neos user of an account may change about itself
 *
 * The schema is written out, preferences is a map, which schematic can't discover from an array
 */
final readonly class Profile implements ProvidesSchema
{
    /**
     * The preference Neos keeps the interface language in, it has no constant for it (see UserPreferences). It has
     * a field of its own, so it is no free-form preference
     */
    public const string INTERFACE_LANGUAGE_PREFERENCE = 'interfaceLanguage';

    /**
     * @param array<string, mixed> $preferences
     */
    public function __construct(
        public string $firstName,
        public string $lastName,
        public EmailAddress|null $email,
        public string $interfaceLanguage,
        public array $preferences,
    ) {
    }

    /**
     * @param string $defaultLanguage the interface language of a user that chose none
     */
    public static function from(Model\User $user, string $defaultLanguage): self
    {
        $preferences = $user->getPreferences()->getPreferences();
        unset($preferences[self::INTERFACE_LANGUAGE_PREFERENCE]);
        return new self(
            $user->getName()->getFirstName(),
            $user->getName()->getLastName(),
            EmailAddress::primaryOf($user),
            $user->getPreferences()->getInterfaceLanguage() ?: $defaultLanguage,
            $preferences,
        );
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= ObjectSchema::create(
            description: 'What the Neos user of an account may change about itself',
            properties: ObjectProperties::create(
                firstName: StringSchema::create(),
                lastName: StringSchema::create(),
                email: Nullable::wrap(EmailAddress::schema()),
                interfaceLanguage: StringSchema::create(
                    description: 'The locale of the Neos backend, one of Neos.Neos.userInterface.availableLanguages. Neos.Neos.userInterface.defaultLanguage if the user chose none',
                    examples: ['en'],
                ),
                preferences: ObjectSchema::create(
                    description: 'Free-form preferences of clients by name, e.g. studio.uiMode. Prefix the names with the client to avoid clashes',
                    examples: [['studio.uiMode' => 'dark']],
                    additionalProperties: true,
                ),
            ),
            additionalProperties: false,
            required: ['firstName', 'lastName', 'email', 'interfaceLanguage', 'preferences'],
        );
    }
}
