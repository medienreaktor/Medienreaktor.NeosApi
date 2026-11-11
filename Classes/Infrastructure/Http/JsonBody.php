<?php

declare(strict_types=1);

namespace Neos\Api\Infrastructure\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * What schematic can't tell from a decoded request body: whether a field was sent at all
 *
 * A request body class has null for a field that is null and for one left out. Where null means something (move a tag
 * to the root), the operation asks the raw JSON body
 */
final readonly class JsonBody
{
    /**
     * Whether the JSON object of the request's body has the field, also with null
     */
    public static function has(ServerRequestInterface $request, string $field): bool
    {
        $body = $request->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $decoded = json_decode($body->getContents(), true);
        return is_array($decoded) && array_key_exists($field, $decoded);
    }
}
