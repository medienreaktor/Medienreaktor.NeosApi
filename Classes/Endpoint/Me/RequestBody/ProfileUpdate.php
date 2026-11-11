<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Me\RequestBody;

use Neos\Api\Endpoint\Me\Schema\Profile;
use Neos\Api\Endpoint\Users\Domain\NameAndEmailChange;
use Neos\Api\Endpoint\Users\Schema\EmailAddress;
use Neos\Api\Endpoint\Users\Schema\PersonNamePart;
use Neos\JsonSchema\Nullable;
use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\JsonSchema\Support\ObjectProperties;
use Neos\Neos\Domain\Model;

/**
 * Changes to the profile: what is left out or null stays as it is, except interfaceLanguage, which null resets to
 * the default (the endpoint reads that from the raw body, schematic can't tell null from left out)
 *
 * The schema is written out, preferences is a map, which schematic can't discover from an array
 */
final readonly class ProfileUpdate implements ProvidesSchema
{
    /**
     * @param array<string, mixed>|null $preferences
     */
    public function __construct(
        public PersonNamePart|null $firstName = null,
        public PersonNamePart|null $lastName = null,
        public EmailAddress|null $email = null,
        public string|null $interfaceLanguage = null,
        public array|null $preferences = null,
    ) {
    }

    public function applyTo(Model\User $user): void
    {
        (new NameAndEmailChange($this->firstName, $this->lastName, $this->email))->applyTo($user);
        if ($this->interfaceLanguage !== null) {
            $user->getPreferences()->setInterfaceLanguage($this->interfaceLanguage);
        }
        if ($this->preferences !== null) {
            $preferences = $user->getPreferences()->getPreferences();
            foreach ($this->preferences as $name => $value) {
                if ($value === null) {
                    unset($preferences[$name]);
                } else {
                    $preferences[$name] = $value;
                }
            }
            $user->getPreferences()->setPreferences($preferences);
        }
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= ObjectSchema::create(
            description: 'Changes to the profile: what is left out or null stays as it is, except interfaceLanguage',
            properties: ObjectProperties::create(
                firstName: Nullable::wrap(PersonNamePart::schema()),
                lastName: Nullable::wrap(PersonNamePart::schema()),
                email: Nullable::wrap(EmailAddress::schema()),
                interfaceLanguage: Nullable::wrap(StringSchema::create(
                    description: 'One of Neos.Neos.userInterface.availableLanguages, null resets it to Neos.Neos.userInterface.defaultLanguage',
                    examples: ['de'],
                )),
                preferences: Nullable::wrap(ObjectSchema::create(
                    description: sprintf('The preferences to set by name, null removes one, the ones left out stay as they are. %s is no preference, set interfaceLanguage instead', Profile::INTERFACE_LANGUAGE_PREFERENCE),
                    examples: [['studio.uiMode' => 'dark', 'studio.sidebar' => null]],
                    additionalProperties: true,
                )),
            ),
            additionalProperties: false,
        );
    }
}
