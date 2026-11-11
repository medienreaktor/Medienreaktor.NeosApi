<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\RequestBody;

use Neos\Api\Endpoint\Nodes\Schema\NodeAggregateId;
use Neos\Api\Endpoint\Workspaces\Schema\DocumentAggregateIdList;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * Which changes of a workspace to discard: the ones in a site, the ones in some documents, or all of them if neither
 * is given (or the body is left out)
 *
 * There is no applyTo(): discarding goes through Neos' WorkspacePublishingService
 */
final readonly class WorkspaceDiscard implements ProvidesSchema
{
    /**
     * @param NodeAggregateId|null $siteAggregateId the site node whose changes to discard, not together with documentAggregateIds
     * @param DocumentAggregateIdList|null $documentAggregateIds the documents whose changes to discard (their content included, not their child documents), at least one, not together with siteAggregateId
     */
    public function __construct(
        public NodeAggregateId|null $siteAggregateId = null,
        public DocumentAggregateIdList|null $documentAggregateIds = null,
    ) {
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
