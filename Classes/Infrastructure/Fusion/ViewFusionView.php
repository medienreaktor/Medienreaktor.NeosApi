<?php

declare(strict_types=1);

namespace Neos\Api\Infrastructure\Fusion;

use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\Fusion\Core\ExceptionHandlers\ThrowingHandler;
use Neos\Neos\View\FusionView;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Neos' FusionView, so a view gets the same globals (request, renderingMode), context (node, documentNode, site) and
 * locale as in the frontend, plus the view's arguments in the context. Fusion errors are thrown instead of being
 * rendered into the output, unless the option renderErrors asks for Neos' handling, e.g. a content element showing its
 * error in its place
 *
 * @internal used by ViewRenderer
 */
class ViewFusionView extends FusionView
{
    public function __construct(array $options = [])
    {
        $this->supportedOptions['renderErrors'] = [false, 'Whether Fusion errors are handled as in the frontend instead of thrown', 'boolean'];
        $this->supportedOptions['arguments'] = [[], 'The arguments of the view, the context variable arguments', 'array'];
        parent::__construct($options);
    }

    public function render(): ResponseInterface|StreamInterface
    {
        $currentNode = $this->getCurrentNode();
        $currentSiteNode = $this->getCurrentSiteNode();
        $fusionRuntime = $this->getFusionRuntime($currentSiteNode);
        $this->setFallbackRuleFromDimension($currentNode->dimensionSpacePoint);

        // as FusionView::render(), with the arguments
        return $fusionRuntime->renderEntryPathWithContext($this->fusionPath, [
            'node' => $currentNode,
            'documentNode' => $this->getClosestDocumentNode($currentNode) ?: $currentNode,
            'site' => $currentSiteNode,
            'arguments' => $this->getOption('arguments'),
        ]);
    }

    protected function getFusionRuntime(Node $currentSiteNode)
    {
        $fusionRuntime = parent::getFusionRuntime($currentSiteNode);
        if ($this->getOption('renderErrors') !== true) {
            $fusionRuntime->overrideExceptionHandler(new ThrowingHandler());
        }
        return $fusionRuntime;
    }
}
