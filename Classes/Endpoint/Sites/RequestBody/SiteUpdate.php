<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Sites\RequestBody;

use Neos\Api\Endpoint\Sites\Schema\DomainId;
use Neos\Api\Endpoint\Sites\Schema\SiteName;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Neos\Domain\Model;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * Changes to a Neos site: what is left out or null stays as it is
 */
final readonly class SiteUpdate implements ProvidesSchema
{
    /**
     * @param DomainId|null $primaryDomainId an active domain of the site to become its primary domain
     */
    public function __construct(
        public SiteName|null $name = null,
        public bool|null $online = null,
        public DomainId|null $primaryDomainId = null,
    ) {
    }

    /**
     * Applies the name and online state, the primary domain is looked up by the caller
     */
    public function applyTo(Model\Site $site): void
    {
        if ($this->name !== null) {
            $site->setName($this->name->value);
        }
        if ($this->online !== null) {
            $site->setState($this->online ? Model\Site::STATE_ONLINE : Model\Site::STATE_OFFLINE);
        }
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
