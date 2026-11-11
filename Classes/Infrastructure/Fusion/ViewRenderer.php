<?php

declare(strict_types=1);

namespace Neos\Api\Infrastructure\Fusion;

use Neos\Api\Infrastructure\Json\JsonValues;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Neos\Domain\Model\RenderingMode;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Renders a Fusion prototype for a node in a rendering mode and decodes the JSON it renders
 *
 * The prototype is rendered on the path neosApiView<Prototype.Name>, it needs no root path. Inheriting from
 * Neos.Api:View makes it a data structure that is encoded to JSON
 */
#[Flow\Scope('singleton')]
final readonly class ViewRenderer
{
    public const string FUSION_PATH = 'neosApiView';

    /**
     * The node carries the content repository, workspace and dimension space point it was found in, so it decides
     * them for the whole rendering: the locale comes from its language dimension, and every node Fusion finds from
     * it is in the same subgraph
     *
     * @param Node $node the context variable node, documentNode is its closest document
     * @param Node $site the site node of the node, from the same subgraph, whose site's Fusion is used
     * @param string $prototypeName the prototype to render, e.g. one inheriting from Neos.Api:View
     * @param RenderingMode $renderingMode the global renderingMode, e.g. frontend or an edit mode
     * @param ServerRequestInterface $httpRequest the API request, Fusion builds URIs with it
     * @param array<mixed> $arguments the context variable arguments
     * @param bool $renderErrors whether Fusion errors are handled as in the frontend (e.g. a content element shows its
     *     error in its place) instead of thrown
     * @return array<string, mixed>|\stdClass|null the data (an empty object as \stdClass), null if the site has no
     *     such prototype
     * @throws InvalidViewArgument if the view can't use one of the arguments
     */
    public function render(Node $node, Node $site, string $prototypeName, RenderingMode $renderingMode, ServerRequestInterface $httpRequest, array $arguments = [], bool $renderErrors = false): array|\stdClass|null
    {
        $view = new ViewFusionView();
        $view->setOption('renderingModeName', $renderingMode->name);
        $view->setOption('arguments', $arguments);
        $view->setOption('renderErrors', $renderErrors);
        $view->assign('request', ActionRequest::fromHttpRequest($httpRequest));
        $view->assign('value', $node);
        $view->assign('site', $site);
        $view->setFusionPath(sprintf('%s<%s>', self::FUSION_PATH, $prototypeName));
        if (!$view->canRenderWithNodeAndPath()) {
            return null;
        }
        try {
            $output = $view->render();
        } catch (\Exception $exception) {
            // Fusion wraps it where it bubbles through a path
            throw InvalidViewArgument::in($exception) ?? $exception;
        }
        return self::decode((string)($output instanceof ResponseInterface ? $output->getBody() : $output), $prototypeName);
    }

    /**
     * The data of the JSON a view rendered: an object as an array, an empty one as an object, so it stays {}
     *
     * @param string $json what the prototype rendered
     * @param string $prototypeName the prototype, for the error
     * @return array<string, mixed>|\stdClass
     * @throws \RuntimeException if it isn't a JSON object
     */
    public static function decode(string $json, string $prototypeName): array|\stdClass
    {
        $json = trim($json);
        // an empty Neos.Fusion:DataStructure is an empty PHP array, which is encoded as []
        if ($json === '[]') {
            $json = '{}';
        }
        try {
            return JsonValues::decodeObject($json);
        } catch (\JsonException | \InvalidArgumentException $exception) {
            throw new \RuntimeException(sprintf('The Fusion prototype %s must render a JSON object, e.g. by inheriting from Neos.Api:View', $prototypeName), 1790838157, $exception);
        }
    }
}
