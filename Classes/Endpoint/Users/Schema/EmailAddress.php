<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Users\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\JsonSchema\Support\StringFormat;
use Neos\Party\Domain\Model\ElectronicAddress;
use Neos\Party\Domain\Model\Person;
use Neos\Schematic\Schematic;

final readonly class EmailAddress implements ProvidesSchema
{
    /**
     * The type of a Neos.Party ElectronicAddress holding an email address, it has no constant for it (see its
     * ElectronicAddressValidator)
     */
    public const string ELECTRONIC_ADDRESS_TYPE = 'Email';

    private function __construct(
        public string $value,
    ) {
    }

    public static function fromString(string $value): self
    {
        return Schematic::instantiate(self::class, $value)->valueOrThrow();
    }

    /**
     * The primary electronic address of the person, null if it is none or not an email address
     */
    public static function primaryOf(Person $person): ?self
    {
        // Neos.Party declares it non-nullable, but a person without one, or whose primary address was removed, has none
        /** @var ElectronicAddress|null $address */
        $address = $person->getPrimaryElectronicAddress();
        return $address?->getType() === self::ELECTRONIC_ADDRESS_TYPE ? self::fromString($address->getIdentifier()) : null;
    }

    /**
     * Makes this the primary electronic address of the person, reusing the one it has if that is an email address
     */
    public function makePrimaryOf(Person $person): void
    {
        // Neos.Party declares it non-nullable, but a person without one, or whose primary address was removed, has none
        /** @var ElectronicAddress|null $address */
        $address = $person->getPrimaryElectronicAddress();
        if ($address?->getType() !== self::ELECTRONIC_ADDRESS_TYPE) {
            $address = new ElectronicAddress();
            $address->setType(self::ELECTRONIC_ADDRESS_TYPE);
            // adds it to the person's addresses as well
            $person->setPrimaryElectronicAddress($address);
        }
        $address->setIdentifier($this->value);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= StringSchema::create(
            description: 'An email address',
            examples: ['editor@example.com'],
            format: StringFormat::email,
        );
    }
}
