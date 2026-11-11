<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Sites\Response;

use Neos\Api\Endpoint\Sites\Schema\Domain;
use Neos\Api\Shared\Response\Created;
use Neos\OpenApi\Binding\TypeReference;
use Neos\OpenApi\Response\ApiResponseWithHeaders;

/**
 * A 201 with the new domain and where to find it
 */
final readonly class DomainCreated implements ApiResponseWithHeaders
{
    use Created;

    public function __construct(
        private Domain $domain,
    ) {
    }

    public static function description(): string
    {
        return 'The domain was added to the site';
    }

    public static function locationDescription(): string
    {
        return 'The URI of the new domain, relative to the request URI';
    }

    public static function bodyType(): TypeReference
    {
        return TypeReference::of(Domain::class);
    }

    public function body(): Domain
    {
        return $this->domain;
    }

    public function headers(): array
    {
        // resolved against POST …/sites/{siteNodeName}/domains, this is …/domains/{domainId}
        return ['Location' => 'domains/' . $this->domain->id->value];
    }
}
