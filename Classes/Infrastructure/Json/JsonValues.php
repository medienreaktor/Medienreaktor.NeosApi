<?php

declare(strict_types=1);

namespace Neos\Api\Infrastructure\Json;

/**
 * Decoded JSON for response bodies
 */
final class JsonValues
{
    /**
     * A JSON object as an array, but an empty object stays an object: as an array it would be encoded as [] again
     *
     * @return array<string, mixed>|\stdClass
     */
    public static function decodeObject(string $json): array|\stdClass
    {
        $value = json_decode($json, flags: JSON_THROW_ON_ERROR);
        if (!$value instanceof \stdClass) {
            throw new \InvalidArgumentException('The JSON must be an object', 1791367001);
        }
        return self::decoded($value);
    }

    /**
     * A decoded JSON value as arrays, but an empty object stays an object: as an array it would be encoded as [] again.
     * Non-empty objects can't stay objects, the serializer of the response only keeps the properties a class declares
     */
    public static function decoded(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $value = get_object_vars($value);
            return $value === [] ? new \stdClass() : array_map(self::decoded(...), $value);
        }
        return is_array($value) ? array_map(self::decoded(...), $value) : $value;
    }
}
