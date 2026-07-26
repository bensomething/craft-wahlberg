<?php

namespace bensomething\wahlberg\events;

use yii\base\Event;

/**
 * Raised with the Preview tab’s HTML, so a plugin can put something in it that the
 * field itself can’t.
 *
 * The case this exists for is markup HTML Purifier would take back out. Inline SVG
 * is the obvious one: the purifier has no notion of it, so an icon resolved before
 * purification is stripped and leaves an empty wrapper behind. By the time this is
 * raised the HTML has been parsed, had its reference tags resolved and been
 * purified, so anything added here is added after the last thing that would remove
 * it.
 *
 * ```php
 * use bensomething\wahlberg\controllers\PreviewController;
 * use bensomething\wahlberg\events\ModifyPreviewEvent;
 * use bensomething\wahlberg\helpers\ReferenceTags;
 * use yii\base\Event;
 *
 * Event::on(
 *     PreviewController::class,
 *     PreviewController::EVENT_MODIFY_PREVIEW,
 *     function(ModifyPreviewEvent $event) {
 *         // `outsideCode()` isn't specific to reference tags: it's there so a
 *         // token an author is documenting in a fence stays as they typed it
 *         $event->html = ReferenceTags::outsideCode(
 *             $event->html,
 *             fn(string $segment) => MyPlugin::render($segment),
 *         );
 *     }
 * );
 * ```
 *
 * Two things to be careful of, since this runs after the sanitiser rather than
 * before it:
 *
 * - Whatever goes in has to be safe on its own account. Markup from your own
 *   files is; anything derived from what an author typed isn’t, and needs
 *   escaping before it goes anywhere near this.
 * - The preview is now a step ahead of `entry.body.html`, which resolves none of
 *   this. Either your own filter puts it back on the template side, or you’ve
 *   made the preview disagree with the page — which is the one thing this field
 *   is otherwise careful not to do.
 */
class ModifyPreviewEvent extends Event
{
    /**
     * @var string The parsed, reference-resolved, purified HTML the Preview tab is
     * about to show. Replace it to change what’s shown.
     */
    public string $html = '';
}
