<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Sites\Response;

use Neos\Api\Endpoint\Sites\Schema\Site;
use Neos\Api\Shared\Response\Created;
use Neos\OpenApi\Binding\TypeReference;
use Neos\OpenApi\Response\ApiResponseWithHeaders;

/**
 * A 201 with the new site and where to find it
 */
final readonly class SiteCreated implements ApiResponseWithHeaders
{
    use Created;

    public function __construct(
        private Site $site,
    ) {
    }

    public static function description(): string
    {
        return 'The site was created';
    }

    public static function locationDescription(): string
    {
        return 'The URI of the new site, relative to the request URI';
    }

    public static function bodyType(): TypeReference
    {
        return TypeReference::of(Site::class);
    }

    public function body(): Site
    {
        return $this->site;
    }

    public function headers(): array
    {
        // resolved against POST …/sites, this is …/sites/{siteNodeName}, whatever the API's URI prefix is
        return ['Location' => 'sites/' . $this->site->nodeName->value];
    }
}
