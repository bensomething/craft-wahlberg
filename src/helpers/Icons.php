<?php

namespace bensomething\wahlberg\helpers;

use bensomething\tabler\web\twig\Extension as TablerIcons;
use Craft;

/**
 * Resolves Tabler Icons’ `{icon:star}` tokens in the Preview tab, when that plugin
 * is installed. An optional integration: Tabler Icons is not a dependency, and
 * nothing here runs unless it’s installed and switched on.
 *
 * The Preview tab only, deliberately. The field’s own `html` leaves these tokens
 * alone, so a template wanting icons pipes it through `|tabler` itself, and running
 * that same filter here is what makes the preview show what the template will
 * produce. For anyone who *doesn’t* pipe it the preview runs a step ahead of
 * `entry.body.html`, the one place in this plugin where the two may disagree.
 *
 * Runs after purification, the opposite way round from reference tags. HTML Purifier
 * has no notion of SVG and takes the icon straight back out, so there’s no purifying
 * this and keeping it. Injecting afterwards is safe because the markup is Tabler’s
 * own files off disk rather than anything an author typed. All the author supplies
 * is the name, and only `[a-z0-9-]+` matches.
 */
abstract class Icons
{
    public const PLUGIN_HANDLE = 'tabler';

    /**
     * What Tabler’s filter goes looking for. Tested before anything else, since most
     * content has no icons and this saves loading that plugin’s classes and asking
     * Craft what’s enabled.
     */
    private const TOKEN = '{icon:';

    /**
     * Replaces the icon tokens in some already-purified HTML, leaving any inside a
     * `<code>` element as the author typed them. A no-op when Tabler Icons isn’t
     * there to render them.
     */
    public static function process(string $html): string
    {
        if (!str_contains($html, self::TOKEN) || !self::available()) {
            return $html;
        }

        $icons = new TablerIcons();

        // Borrows the reference tag parser’s split, which isn’t specific to reference
        // tags. Skipping code is right here for the same reason it is there:
        // `{icon:star}` in a fence is an author documenting the token, not asking
        // for the picture.
        return ReferenceTags::outsideCode(
            $html,
            static fn(string $segment): string => $icons->replaceIcons($segment),
        );
    }

    /**
     * Whether Tabler Icons is installed, enabled, and still offers the filter this
     * leans on.
     */
    public static function available(): bool
    {
        // Asked first, and without going near Craft: the class is simply absent when
        // the plugin isn’t installed, which is the case this has to be cheap for.
        // `method_exists` because `replaceIcons()` is Tabler’s to rename, and if it
        // ever does the preview should show the token rather than raise a 500.
        if (!class_exists(TablerIcons::class) || !method_exists(TablerIcons::class, 'replaceIcons')) {
            return false;
        }

        // Installed but switched off means Twig has no `|tabler` filter either, so no
        // template could resolve these and the preview shouldn’t imply otherwise.
        return Craft::$app->getPlugins()->isPluginEnabled(self::PLUGIN_HANDLE);
    }
}
