<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Me\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * What the access token of a request grants on top of its account's roles
 */
final readonly class AccessTokenGrant implements ProvidesSchema
{
    public function __construct(
        public ClientIdentifier $client,
        public ScopeList $scopes,
    ) {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
