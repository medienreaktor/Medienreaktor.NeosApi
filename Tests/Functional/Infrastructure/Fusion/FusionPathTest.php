<?php
declare(strict_types=1);

namespace Neos\Api\Tests\Functional\Infrastructure\Fusion;

use Neos\Api\Endpoint\Views\Response\RenderedView;
use Neos\Api\Infrastructure\Fusion\InvalidViewArgument;
use Neos\Api\Infrastructure\Fusion\ViewRenderer;
use Neos\Flow\Tests\FunctionalTestCase;
use Neos\Fusion\Core\ExceptionHandlers\ThrowingHandler;
use Neos\Fusion\Core\FusionGlobals;
use Neos\Fusion\Core\FusionSourceCode;
use Neos\Fusion\Core\FusionSourceCodeCollection;
use Neos\Fusion\Core\Runtime;
use Neos\Fusion\Core\RuntimeFactory;
use Neos\Neos\Domain\Model\RenderingMode;
use Neos\OpenApi\Binding\TypeBinding;
use PHPUnit\Framework\Attributes\Test;

/**
 * Neos.Api:FusionPath and Neos.Api:View with a Fusion runtime of their own: rendering a view through the endpoint needs
 * a content repository, which the Testing context (SQLite) doesn't have
 */
class FusionPathTest extends FunctionalTestCase
{
    private const string FUSION = <<<'FUSION'
        prototype(Neos.Neos:ContentCase) < prototype(Neos.Fusion:Value) {
            value = ${'the case for ' + node}
        }
        root = Neos.Fusion:Join {
            main = Neos.Fusion:Value {
                value = 'the element'
            }
            content = Neos.Fusion:Join {
                itemRenderer = Neos.Neos:ContentCase {
                    value = ${'the outer case for ' + node}
                }
            }
        }
        rawContent = Neos.Fusion:Join {
            main = Neos.Fusion:Value {
                value = 'the raw element'
            }
        }
        elsewhere = Neos.Fusion:Value {
            value = 'not on the page'
        }
        fusionPath = Neos.Api:FusionPath {
            path = ${path}
        }
        emptyView = Neos.Api:View
        FUSION;

    #[Test]
    public function rendersPathsOfThePage(): void
    {
        $inPlace = self::renderingMode('inPlace', '');

        self::assertSame('the element', $this->renderFusionPath('root<Neos.Fusion:Join>/main<Neos.Fusion:Value>', $inPlace));
        self::assertSame('the element', $this->renderFusionPath('root/main', $inPlace));
        // the mode's own path, which Neos' root case renders instead
        self::assertSame('the raw element', $this->renderFusionPath('rawContent<Neos.Fusion:Join>/main', self::renderingMode('rawContent', 'rawContent')));
    }

    #[Test]
    public function rendersElementsFromTheirLastContentCase(): void
    {
        $inPlace = self::renderingMode('inPlace', '');
        $case = 'root<Neos.Fusion:Join>/content<Neos.Fusion:Join>/itemRenderer<Neos.Neos:ContentCase>';

        // the element the case chose, and what it rendered below it (e.g. a renderer picked from a property), are chosen again
        foreach (['/default<Neos.Fusion:Matcher>/element<Vendor.Site:Old>', '/default<Neos.Fusion:Matcher>/element<Vendor.Site:Old>/renderer<Vendor.Site:Old.Renderer>'] as $below) {
            self::assertSame('the outer case for text', $this->renderFusionPath($case . $below, $inPlace, ['node' => 'text']), $below);
        }
        // the case itself as given
        self::assertSame('the outer case for text', $this->renderFusionPath($case, $inPlace, ['node' => 'text']));

        // an element in a collection of an element: from the inner case, the last one, by its prototype
        $inner = $case . '/default<Neos.Fusion:Matcher>/element<Vendor.Site:Box>/column<Neos.Neos:ContentCollection>/itemRenderer<Neos.Neos:ContentCase>';
        self::assertSame('the case for text', $this->renderFusionPath($inner . '/default<Neos.Fusion:Matcher>/element<Vendor.Site:Inner>', $inPlace, ['node' => 'text']));
    }

    #[Test]
    public function rendersNothingInTheFrontendRenderingMode(): void
    {
        $this->expectExceptionCode(1791802334);
        $this->renderFusionPath('root/main', RenderingMode::createFrontend());
    }

    #[Test]
    public function rendersNoPathsOutsideThePage(): void
    {
        foreach (['elsewhere', 'root', 'rawContent/main', 'elsewhere/root/main', 'root/__meta/process', 'root//main'] as $path) {
            try {
                $this->renderFusionPath($path, self::renderingMode('inPlace', ''));
                self::fail(sprintf('%s was rendered', $path));
            } catch (InvalidViewArgument $exception) {
                self::assertSame(1791802335, $exception->getCode(), $path . ': ' . $exception->getMessage());
            }
        }
    }

    #[Test]
    public function emptyViewsAreEmptyObjects(): void
    {
        $json = (string)$this->runtime(RenderingMode::createFrontend())->render('emptyView');
        // an empty data structure is an empty array
        self::assertSame('[]', $json);

        $data = ViewRenderer::decode($json, 'Neos.Api:View');
        self::assertEquals(new \stdClass(), $data);
        self::assertSame('{}', json_encode(TypeBinding::serialize(RenderedView::bodyType(), (new RenderedView($data))->body())));
    }

    #[Test]
    public function decodesViewsAsArraysButEmptyObjects(): void
    {
        self::assertEquals(['items' => [['label' => 'Home', 'properties' => new \stdClass()]]], ViewRenderer::decode(' {"items": [{"label": "Home", "properties": {}}]} ', 'X'));

        foreach (['', '"a string"', '[1, 2]', '{not json}'] as $json) {
            try {
                ViewRenderer::decode($json, 'Vendor.Site:Api.View');
                self::fail(sprintf('%s was decoded', $json));
            } catch (\RuntimeException $exception) {
                self::assertSame(1790838157, $exception->getCode(), $json);
            }
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function renderFusionPath(string $path, RenderingMode $renderingMode, array $context = []): mixed
    {
        $runtime = $this->runtime($renderingMode);
        $runtime->pushContextArray(['path' => $path] + $context);
        try {
            return $runtime->render('fusionPath');
        } catch (\Exception $exception) {
            // Fusion wraps it where it bubbles through a path
            throw InvalidViewArgument::in($exception) ?? $exception;
        }
    }

    private function runtime(RenderingMode $renderingMode): Runtime
    {
        $runtime = $this->objectManager->get(RuntimeFactory::class)->createFromSourceCode(
            new FusionSourceCodeCollection(
                FusionSourceCode::fromFilePath('resource://Neos.Fusion/Private/Fusion/Root.fusion'),
                FusionSourceCode::fromFilePath('resource://Neos.Api/Private/Fusion/Prototypes/View.fusion'),
                FusionSourceCode::fromFilePath('resource://Neos.Api/Private/Fusion/Prototypes/FusionPath.fusion'),
                FusionSourceCode::fromString(self::FUSION),
            ),
            FusionGlobals::fromArray(['renderingMode' => $renderingMode]),
        );
        $runtime->overrideExceptionHandler(new ThrowingHandler());
        return $runtime;
    }

    private static function renderingMode(string $name, string $fusionPath): RenderingMode
    {
        return RenderingMode::createFromConfiguration($name, ['isEditingMode' => true, 'fusionRenderingPath' => $fusionPath]);
    }
}
