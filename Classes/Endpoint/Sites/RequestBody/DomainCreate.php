<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Sites\RequestBody;

use Neos\Api\Endpoint\Sites\Schema\Hostname;
use Neos\Api\Endpoint\Sites\Schema\Port;
use Neos\Api\Endpoint\Sites\Schema\UriScheme;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Neos\Domain\Model;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A domain to add to a site
 */
final readonly class DomainCreate implements ProvidesSchema
{
    /**
     * @param Hostname $hostname unique among all domains
     * @param UriScheme|null $scheme left out, the domain works with the scheme of the request
     * @param Port|null $port left out, the domain works with the port of the request
     * @param bool|null $active true if left out
     */
    public function __construct(
        public Hostname $hostname,
        public UriScheme|null $scheme = null,
        public Port|null $port = null,
        public bool|null $active = null,
    ) {
    }

    /**
     * Applies the hostname, scheme, port and active state, the site is set by the caller
     */
    public function applyTo(Model\Domain $domain): void
    {
        $domain->setHostname($this->hostname->value);
        $domain->setScheme($this->scheme?->value);
        $domain->setPort($this->port?->value);
        $domain->setActive($this->active ?? true);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
