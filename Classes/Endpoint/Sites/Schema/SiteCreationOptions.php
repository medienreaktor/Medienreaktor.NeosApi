<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Sites\Schema;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * What a new site can be created with
 */
final readonly class SiteCreationOptions implements ProvidesSchema
{
    /**
     * @param PackageKeyList $packages the installed site packages (of type neos-site)
     * @param SiteNodeTypeList $nodeTypes the non-abstract subtypes of Neos.Neos:Site
     */
    public function __construct(
        public PackageKeyList $packages,
        public SiteNodeTypeList $nodeTypes,
    ) {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
