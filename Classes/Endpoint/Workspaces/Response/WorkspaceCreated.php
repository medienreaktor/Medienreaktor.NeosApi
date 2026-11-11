<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Workspaces\Response;

use Neos\Api\Endpoint\Workspaces\Schema\Workspace;
use Neos\Api\Shared\Response\Created;
use Neos\OpenApi\Binding\TypeReference;
use Neos\OpenApi\Response\ApiResponseWithHeaders;

/**
 * A 201 with the new workspace and where to find it
 */
final readonly class WorkspaceCreated implements ApiResponseWithHeaders
{
    use Created;

    public function __construct(
        private Workspace $workspace,
    ) {
    }

    public static function description(): string
    {
        return 'The workspace was created';
    }

    public static function locationDescription(): string
    {
        return 'The URI of the new workspace, relative to the request URI';
    }

    public static function bodyType(): TypeReference
    {
        return TypeReference::of(Workspace::class);
    }

    public function body(): Workspace
    {
        return $this->workspace;
    }

    public function headers(): array
    {
        // resolved against POST …/cr/{contentRepositoryId}/workspaces, this is …/workspaces/{workspaceName}
        return ['Location' => 'workspaces/' . $this->workspace->name->value];
    }
}
