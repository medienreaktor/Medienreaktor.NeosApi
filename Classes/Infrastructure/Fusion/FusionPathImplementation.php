<?php
declare(strict_types=1);

namespace Neos\Api\Infrastructure\Fusion;

use Neos\Fusion\FusionObjects\AbstractFusionObject;
use Neos\Neos\Domain\Model\RenderingMode;

/**
 * Renders the absolute Fusion path, as the Neos UI renders a content element after a change: with the path the element
 * was rendered at (its data-__neos-fusion-path attribute in an edit mode), so it gets the prototypes and settings that
 * apply there, e.g. a wrapper its container adds to its children. The context is the one of the object, node is the
 * element
 *
 * Only such paths are rendered, not any path of the site's Fusion: below the path Neos renders a page at (root, or the
 * rendering mode's own one, e.g. rawContent), without __meta, and only in a rendering mode other than frontend, which
 * are the ones with these attributes and need Neos.Neos:Backend.GeneralAccess (checked by renderView)
 *
 * A path that ends in or passes through the element a Neos.Neos:ContentCase chose is rendered from the last (innermost)
 * case, so everything below it is resolved again for the node as it is now: a change that makes the case choose
 * another element shows, and so does one that changes what the element renders below it (e.g. a renderer picked from
 * a property). The Neos UI's RenderedNodeDomAddress::getFusionPathForContentRendering() only cuts a matcher and element
 * at the end. Paths without a case, e.g. a tethered content collection's at a named path, render as given
 *
 * @internal the implementation of Neos.Api:FusionPath
 */
class FusionPathImplementation extends AbstractFusionObject
{
    /**
     * Path segments, prototype names in angle brackets and the characters of their names
     */
    private const string PATTERN = '#^[a-zA-Z0-9_/<>.:@\\\\-]+$#';

    /**
     * The content case of a content collection, whose element paths start below it
     */
    private const string CONTENT_CASE = '/itemRenderer<Neos.Neos:ContentCase>';

    /**
     * Where Neos' FusionView starts rendering a page
     */
    private const string PAGE_PATH = 'root';

    public function evaluate(): mixed
    {
        $renderingMode = $this->runtime->fusionGlobals->get('renderingMode');
        if (!$renderingMode instanceof RenderingMode || $renderingMode->name === RenderingMode::FRONTEND) {
            throw new InvalidViewArgument('A Fusion path is only rendered in a rendering mode other than frontend, e.g. inPlace, whose content elements have it', 1791802334);
        }
        $path = $this->fusionValue('path');
        if (!is_string($path) || $path === '') {
            throw new InvalidViewArgument('A Fusion path to render is needed', 1791802331);
        }
        if (!preg_match(self::PATTERN, $path)) {
            throw new InvalidViewArgument(sprintf('"%s" is no Fusion path', $path), 1791802332);
        }
        if (!self::isContentPath($path, $renderingMode)) {
            throw new InvalidViewArgument(sprintf('The Fusion path %s is no path of content on the page, it must start at %s', $path, implode(' or ', self::pagePaths($renderingMode))), 1791802335);
        }
        $path = self::fromContentCase($path);
        if (!$this->runtime->canRender($path)) {
            throw new InvalidViewArgument(sprintf('There is nothing to render at the Fusion path %s', $path), 1791802333);
        }
        return $this->runtime->render($path);
    }

    /**
     * The path up to its last content case, if there is anything below it, else the path
     */
    private static function fromContentCase(string $path): string
    {
        $position = strrpos($path, self::CONTENT_CASE . '/');
        return $position !== false ? substr($path, 0, $position + strlen(self::CONTENT_CASE)) : $path;
    }

    /**
     * Whether the path is below one Neos renders a page at in the rendering mode, as the paths of content elements are,
     * and has no empty or __meta segments, which they never have
     */
    private static function isContentPath(string $path, RenderingMode $renderingMode): bool
    {
        // the segment names, without their prototypes
        $names = array_map(static fn (string $segment) => (string)preg_replace('#<[^<>]*>$#', '', $segment), explode('/', $path));
        return count($names) > 1
            && in_array($names[0], self::pagePaths($renderingMode), true)
            && !in_array('', $names, true)
            && !in_array('__meta', $names, true);
    }

    /**
     * root, and the rendering mode's own path, which Neos' root case renders instead, e.g. rawContent
     *
     * @return list<string>
     */
    private static function pagePaths(RenderingMode $renderingMode): array
    {
        return array_values(array_unique(array_filter([self::PAGE_PATH, $renderingMode->fusionPath], static fn (string $path) => $path !== '')));
    }
}
