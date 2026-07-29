<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Service;

use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Workspace\Workspace;

/**
 * Contributes additional, package-specific data to the workspace JSON
 * representation produced by the {@see WorkspaceSerializer}.
 *
 * Implementations are registered in the settings under
 * Medienreaktor.NeosApi.workspaceDataEnrichers; the registration key becomes
 * the key of the contribution inside the workspace's "extensions" object, so
 * clients can address contributions without the API knowing their shape:
 *
 *     Medienreaktor:
 *       NeosApi:
 *         workspaceDataEnrichers:
 *           'Vendor.Package:Something':
 *             enricher: 'Vendor\Package\Api\SomethingWorkspaceDataEnricher'
 *             position: 'end'
 *
 * Enrichers run for every serialized workspace of a request, so they must be
 * cheap: batch or memoize lookups per request (the serializer is a singleton,
 * enrichers resolved through it live for the request as well).
 */
interface WorkspaceDataEnricherInterface
{
    /**
     * Return the contribution for the given workspace, or null to contribute
     * nothing (the key is then omitted from the "extensions" object entirely,
     * which is how clients distinguish "not applicable" from "empty").
     *
     * The account's read permission on the workspace has already been checked
     * by the serializer; implementations enforcing stricter visibility must do
     * so themselves.
     *
     * @return array<string, mixed>|null
     */
    public function enrich(ContentRepositoryId $contentRepositoryId, Workspace $workspace): ?array;
}
