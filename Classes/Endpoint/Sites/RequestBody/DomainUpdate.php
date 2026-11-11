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
 * Changes to a domain: what is left out stays as it is, null as well except for scheme and port, which it removes
 */
final readonly class DomainUpdate implements ProvidesSchema
{
    /**
     * @param Hostname|null $hostname unique among all domains
     * @param UriScheme|null $scheme null removes it, the domain works with the scheme of the request then
     * @param Port|null $port null removes it, the domain works with the port of the request then
     */
    public function __construct(
        public Hostname|null $hostname = null,
        public UriScheme|null $scheme = null,
        public Port|null $port = null,
        public bool|null $active = null,
    ) {
    }

    /**
     * Applies what isn't null, the endpoint removes a scheme or port that is null in the body (schematic can't tell
     * it from one left out)
     */
    public function applyTo(Model\Domain $domain): void
    {
        if ($this->hostname !== null) {
            $domain->setHostname($this->hostname->value);
        }
        if ($this->scheme !== null) {
            $domain->setScheme($this->scheme->value);
        }
        if ($this->port !== null) {
            $domain->setPort($this->port->value);
        }
        if ($this->active !== null) {
            $domain->setActive($this->active);
        }
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
