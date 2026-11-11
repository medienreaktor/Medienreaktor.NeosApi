<?php

declare(strict_types=1);

namespace Neos\Api\Endpoint\Views;

use Neos\Api\Endpoint\ContentRepositories\Schema\ContentRepositoryId;
use Neos\Api\Endpoint\Nodes\Schema\DimensionSpacePoint;
use Neos\Api\Endpoint\Nodes\Schema\NodeAggregateId;
use Neos\Api\Endpoint\Nodes\Domain\SubgraphResolver;
use Neos\Api\Endpoint\Workspaces\Schema\WorkspaceName;
use Neos\Api\Endpoint\Views\Response\RenderedView;
use Neos\Api\Endpoint\Views\Schema\RenderingModeName;
use Neos\Api\Endpoint\Views\Schema\View;
use Neos\Api\Endpoint\Views\Schema\ViewName;
use Neos\Api\Endpoint\Views\Schema\ViewList;
use Neos\Api\Endpoint\Views\Schema\ViewListing;
use Neos\Api\Infrastructure\ContentRepository\ContentSubgraphs;
use Neos\Api\Infrastructure\ContentRepository\SiteFinder;
use Neos\Api\Infrastructure\Fusion\InvalidViewArgument;
use Neos\Api\Infrastructure\Fusion\ViewRenderer;
use Neos\Api\Security\AccountPrivileges;
use Neos\Api\Security\ApiAuthContextProvider;
use Neos\Api\Security\ApiCaller;
use Neos\Api\Security\ApiScopes;
use Neos\Api\Shared\Parameter\Arguments;
use Neos\Api\Shared\Response\BadRequest;
use Neos\Api\Shared\Response\Forbidden;
use Neos\Api\Shared\Response\NotFound;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindClosestNodeFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\SharedModel;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Model\RenderingMode;
use Neos\Neos\Domain\Service\RenderingModeService;
use Neos\Neos\Domain\Service\NodeTypeNameFactory;
use Neos\OpenApi\Attributes\AuthContext;
use Neos\OpenApi\Attributes\Operation;
use Neos\OpenApi\Attributes\Parameter;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Views of nodes: data Fusion prototypes build, e.g. a navigation with grouping folders or external data
 *
 * A view is a prototype inheriting from Neos.Api:View, made available under a name in the Neos.Api.views settings.
 * It is rendered with the Fusion of the node's site and the same context as in the frontend: node, documentNode, site,
 * plus the arguments of the request
 */
final readonly class Views
{
    /**
     * Neos grants the rendering modes other than frontend only to accounts with it, see
     * RenderingModeService::findByCurrentUser()
     */
    private const string BACKEND_ACCESS = 'Neos.Neos:Backend.GeneralAccess';

    /**
     * @var array<string, array{prototype: string, description?: string|null, renderErrors?: bool}>
     */
    private array $views;

    /**
     * @var list<string>
     */
    private array $renderingModeNames;

    /**
     * @param array<string, array{prototype: string, description?: string|null, renderErrors?: bool}|null> $views
     * @param array<string, mixed> $editPreviewModes
     */
    public function __construct(
        private ContentSubgraphs $contentSubgraphs,
        private SubgraphResolver $subgraphResolver,
        private SiteFinder $siteFinder,
        private RenderingModeService $renderingModeService,
        private AccountPrivileges $accountPrivileges,
        private ViewRenderer $viewRenderer,
        #[Flow\InjectConfiguration(path: 'views')]
        array $views,
        #[Flow\InjectConfiguration(path: 'userInterface.editPreviewModes', package: 'Neos.Neos')]
        array $editPreviewModes,
    ) {
        // a view or rendering mode can be removed by setting it to null
        $this->views = array_filter($views, static fn (array|null $view) => $view !== null);
        $this->renderingModeNames = [RenderingMode::FRONTEND, ...array_map('strval', array_keys(array_filter($editPreviewModes, static fn (mixed $mode) => $mode !== null)))];
    }

    #[Operation(
        path: '/views',
        method: 'GET',
        summary: 'List all views',
        description: 'The views in the order of the Neos.Api.views settings. Each site renders a view with its own Fusion, a site may not have the prototype of every view.',
        operationId: 'listViews',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::VIEWS_READ],
        ],
    )]
    public function list(): ViewListing
    {
        $views = [];
        foreach ($this->views as $name => $view) {
            $views[] = new View(ViewName::fromString((string)$name), $view['description'] ?? null);
        }
        return ViewListing::of(new ViewList(...$views));
    }

    #[Operation(
        path: '/views/{viewName}',
        method: 'GET',
        summary: 'Render a view',
        description: 'Renders the view\'s Fusion prototype for a node with the Fusion of the node\'s site. Without a node, it is rendered for the site node of the default site (Neos.Neos.defaultSiteNodeName, else the first online site; with contentRepositoryId the content repository\'s default site, as for getNode), as the frontend renders its home page. The workspace is live and the dimension space point the default site\'s default one unless given. In the frontend rendering mode and the live workspace, disabled nodes are left out as in the frontend, otherwise they are visible to accounts that may see them, as in the preview of the Neos backend. Rendering modes other than frontend need the privilege Neos.Neos:Backend.GeneralAccess, as in Neos. The view gets the arguments as the Fusion context variable arguments, which ones it takes is up to it, an argument it can\'t use is a 400. The shape of the data is up to the view.',
        operationId: 'renderView',
        security: [
            ApiAuthContextProvider::SCOPES => [ApiScopes::VIEWS_READ],
        ],
    )]
    public function render(
        ViewName $viewName,
        ServerRequestInterface $request,
        #[AuthContext] ApiCaller $caller,
        #[Parameter(in: 'query', description: 'The content repository of the node, the one of the default site if omitted')]
        ContentRepositoryId|null $contentRepositoryId = null,
        #[Parameter(in: 'query', description: 'The aggregate id of the node to render the view for. The site node of the default site if omitted')]
        NodeAggregateId|null $nodeAggregateId = null,
        #[Parameter(in: 'query', description: 'The workspace to read the node in, live if omitted')]
        WorkspaceName|null $workspaceName = null,
        #[Parameter(in: 'query', description: 'The dimension space point to read the node in, as JSON. If omitted, the default one of the content repository\'s default site, as for getNode')]
        DimensionSpacePoint|null $dimensionSpacePoint = null,
        #[Parameter(in: 'query', description: 'The rendering mode, the Fusion global renderingMode. frontend if omitted, the Neos UI uses e.g. inPlace')]
        RenderingModeName|null $renderingMode = null,
        #[Parameter(in: 'query', description: 'The arguments of the view: arguments[name]=value, arrays as arguments[name][]=value, e.g. arguments[fusionPath] for fusionPathHtml')]
        Arguments|null $arguments = null,
    ): RenderedView|NotFound|BadRequest|Forbidden {
        $view = $this->views[$viewName->value] ?? null;
        if ($view === null) {
            return NotFound::because(sprintf('There is no view %s', $viewName->value));
        }
        $mode = $this->renderingMode($renderingMode);
        if ($mode === null) {
            return BadRequest::because(sprintf('There is no rendering mode %s, there are: %s', $renderingMode?->value, implode(', ', $this->renderingModeNames)));
        }
        // the one rule the operation's security can't express: it depends on an argument
        if ($mode->name !== RenderingMode::FRONTEND && !$this->accountPrivileges->isGranted($caller->account, self::BACKEND_ACCESS)) {
            return Forbidden::because(sprintf('The rendering mode %s needs the privilege %s', $mode->name, self::BACKEND_ACCESS));
        }
        $subgraph = $this->subgraphResolver->resolve(
            $contentRepositoryId,
            $workspaceName,
            $dimensionSpacePoint,
            self::excludeDisabled($workspaceName?->toWorkspaceName() ?? SharedModel\Workspace\WorkspaceName::forLive(), $mode),
            withoutContentRepository: 'There is no default site to render the view for, give a contentRepositoryId',
        );
        if (!$subgraph instanceof ContentSubgraphInterface) {
            return $subgraph;
        }
        $nodes = $nodeAggregateId !== null ? $this->node($subgraph, $nodeAggregateId) : $this->defaultSiteNode($subgraph);
        if ($nodes instanceof NotFound) {
            return $nodes;
        }
        [$contextNode, $site] = $nodes;
        try {
            // the parameter only validates them, see Arguments
            $data = $this->viewRenderer->render($contextNode, $site, $view['prototype'], $mode, $request, Arguments::values($request), $view['renderErrors'] ?? false);
        } catch (InvalidViewArgument $exception) {
            return BadRequest::because($exception->getMessage());
        }
        if ($data === null) {
            return NotFound::because(sprintf('The site of the node has no Fusion prototype %s for the view %s', $view['prototype'], $viewName->value));
        }
        return new RenderedView($data);
    }

    /**
     * @return array{Node, Node}|NotFound the node and its site node
     */
    private function node(ContentSubgraphInterface $subgraph, NodeAggregateId $nodeAggregateId): array|NotFound
    {
        $node = $this->subgraphResolver->resolveNodeIn($subgraph, $nodeAggregateId->toNodeAggregateId());
        if (!$node instanceof Node) {
            return $node;
        }
        $site = $subgraph->findClosestNode($node->aggregateId, FindClosestNodeFilter::create(nodeTypes: NodeTypeNameFactory::NAME_SITE));
        if ($site === null) {
            return NotFound::because(sprintf('The node %s belongs to no site, so there is no Fusion to render it with', $nodeAggregateId->value));
        }
        return [$node, $site];
    }

    /**
     * The site node of the content repository's default site, which the frontend renders for the home page
     *
     * @return array{Node, Node}|NotFound the site node twice, as the node and its site node
     */
    private function defaultSiteNode(ContentSubgraphInterface $subgraph): array|NotFound
    {
        $site = $this->siteFinder->findDefault($subgraph->getContentRepositoryId());
        if ($site === null) {
            return NotFound::because(sprintf('There is no site in the content repository %s to render the view for, give a nodeAggregateId', $subgraph->getContentRepositoryId()->value));
        }
        $siteNode = $this->contentSubgraphs->findSiteNodeIn($subgraph, $site);
        if ($siteNode === null) {
            return NotFound::because(sprintf(
                'The default site %s has no site node in the workspace %s and the dimension space point %s',
                $site->getNodeName()->value,
                $subgraph->getWorkspaceName()->value,
                $subgraph->getDimensionSpacePoint()->toJson(),
            ));
        }
        return [$siteNode, $siteNode];
    }

    /**
     * As the frontend (frontend rendering mode in live: never with disabled nodes), otherwise as the preview of the
     * Neos backend
     */
    private static function excludeDisabled(SharedModel\Workspace\WorkspaceName $workspaceName, RenderingMode $renderingMode): bool
    {
        return $workspaceName->isLive() && $renderingMode->name === RenderingMode::FRONTEND;
    }

    /**
     * The rendering mode of the name, frontend without one, null if there is none of the name
     */
    private function renderingMode(?RenderingModeName $name): ?RenderingMode
    {
        if ($name === null) {
            return RenderingMode::createFrontend();
        }
        // checked up front: the service throws for an unknown one
        return in_array($name->value, $this->renderingModeNames, true) ? $this->renderingModeService->findByName($name->value) : null;
    }
}
