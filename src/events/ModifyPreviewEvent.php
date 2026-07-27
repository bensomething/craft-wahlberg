<?php

namespace bensomething\wahlberg\events;

use yii\base\Event;

/**
 * Raised with the Preview tab’s HTML, for markup HTML Purifier would otherwise
 * strip. Inline SVG is the case it exists for: the purifier has no notion of it,
 * so an icon resolved any earlier comes straight back off.
 *
 * Two consequences of running after the sanitiser: what goes in has to be safe on
 * its own account, and the preview is now a step ahead of `entry.body.html` unless
 * a filter puts it back on the template side.
 *
 * ```php
 * Event::on(
 *     PreviewController::class,
 *     PreviewController::EVENT_MODIFY_PREVIEW,
 *     function(ModifyPreviewEvent $event) {
 *         // Leaves tokens in a fence as the author typed them
 *         $event->html = ReferenceTags::outsideCode(
 *             $event->html,
 *             fn(string $segment) => MyPlugin::render($segment),
 *         );
 *     }
 * );
 * ```
 */
class ModifyPreviewEvent extends Event
{
    /**
     * @var string The parsed, reference-resolved, purified HTML about to be shown
     */
    public string $html = '';
}
