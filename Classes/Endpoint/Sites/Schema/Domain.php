<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Sites\Schema;

use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Neos\Domain\Model;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A domain a site is reachable with
 */
final readonly class Domain implements ProvidesSchema
{
    /**
     * @param UriScheme|null $scheme null if the domain works with the scheme of the request
     * @param Port|null $port null if the domain works with the port of the request
     * @param bool $isPrimary whether it is the site's explicit primary domain
     * @param string $url the domain as Neos writes it, [scheme://]hostname[:port], e.g. https://www.example.com, or example.com:8080 for a domain without a scheme (it works with the scheme of the request): a URL only if the domain has a scheme
     */
    public function __construct(
        public DomainId $id,
        public Hostname $hostname,
        public UriScheme|null $scheme,
        public Port|null $port,
        public bool $active,
        public bool $isPrimary,
        public string $url,
    ) {
    }

    public static function from(Model\Domain $domain, PersistenceManagerInterface $persistenceManager): self
    {
        $site = $domain->getSite();
        return new self(
            DomainId::fromString($persistenceManager->getIdentifierByObject($domain)),
            Hostname::fromString($domain->getHostname()),
            $domain->getScheme() !== null ? UriScheme::from($domain->getScheme()) : null,
            $domain->getPort() !== null ? Port::fromInteger($domain->getPort()) : null,
            (bool)$domain->getActive(),
            $site->getPrimaryDomain(false) === $domain,
            (string)$domain,
        );
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
