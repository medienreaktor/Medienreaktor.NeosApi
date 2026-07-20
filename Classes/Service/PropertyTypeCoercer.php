<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Service;

use Neos\ContentRepository\Core\NodeType\NodeType;
use Neos\Flow\Annotations as Flow;

/**
 * Coerces scalar property values in a command payload from their JSON transport
 * form into the PHP type the node type declares, for the types where the two
 * differ.
 *
 * Today that is DateTime: the read API emits a date property as a plain ISO
 * 8601 string (the content repository's serialized form), and any client -
 * ours, the MCP server, a third party - naturally sends that same string back.
 * But SetNodeProperties (unlike its serialized sibling) validates every value
 * against the node type's declared type with a bare `instanceof` check
 * (PropertyType::isMatchedBy), and a string is not a \DateTimeInterface, so the
 * command is rejected before it runs. This mirrors what PropertyValueHydrator
 * does for object references: resolve the transport form to the real PHP value
 * up front, so the write API accepts exactly what the read API emits.
 *
 * A date property is matched by any \DateTimeInterface (all of DateTime,
 * \DateTime, DateTimeImmutable, \DateTimeInterface collapse to one date type in
 * the CR), so a \DateTimeImmutable built from the string satisfies every date
 * declaration. Non-date properties and non-string values pass through
 * untouched.
 */
final class PropertyTypeCoercer
{
    /**
     * The node type declarations the content repository treats as a date, kept
     * in sync with PropertyType::tryFromString.
     */
    private const DATE_TYPES = [
        'DateTime',
        '\DateTime',
        'DateTimeImmutable',
        '\DateTimeImmutable',
        'DateTimeInterface',
        '\DateTimeInterface',
    ];

    /**
     * Return the property map with date-typed string values replaced by
     * \DateTimeImmutable instances. Throws when a date property carries an
     * unparseable string, so the caller can report it as an invalid payload.
     *
     * @param array<string, mixed> $propertyValues
     * @return array<string, mixed>
     */
    public function coerce(NodeType $nodeType, array $propertyValues): array
    {
        foreach ($propertyValues as $name => $value) {
            if (!is_string($value) || $value === '') {
                continue;
            }
            if (!in_array($nodeType->getPropertyType($name), self::DATE_TYPES, true)) {
                continue;
            }
            try {
                $propertyValues[$name] = new \DateTimeImmutable($value);
            } catch (\Exception $exception) {
                throw new \InvalidArgumentException(sprintf(
                    'Property "%s" is not a valid date: "%s".',
                    $name,
                    $value
                ), 1753000000, $exception);
            }
        }
        return $propertyValues;
    }
}
