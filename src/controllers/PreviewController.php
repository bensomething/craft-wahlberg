<?php

namespace bensomething\wahlberg\controllers;

use bensomething\wahlberg\fields\MarkdownField;
use bensomething\wahlberg\helpers\Icons;
use bensomething\wahlberg\models\MarkdownData;
use Craft;
use craft\web\Controller;
use yii\web\Response;

/**
 * Renders the Preview tab. Parsing happens server-side with the same parser and
 * purifier settings the field itself uses, so the preview can’t drift from the
 * front end.
 */
class PreviewController extends Controller
{
    public function actionIndex(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requireCpRequest();

        $markdown = trim((string)$this->request->getBodyParam('markdown', ''));

        if ($markdown === '') {
            return $this->asJson(['html' => '']);
        }

        $field = $this->field();

        // Reference tags resolve against the current site, so put the request on the
        // site the element is being edited in. Otherwise a preview on a secondary
        // site shows the primary site’s URLs
        $this->useSite();

        // No field to go on? Parse with the requested flavour, but purify regardless.
        // The browser doesn’t get to turn sanitization off
        $value = $field?->normalizeValue($markdown) ?? new MarkdownData(
            $markdown,
            $this->flavour(),
            true,
        );

        // Icons go in last, after the field has purified: the purifier would strip
        // the SVG back out again. Only the preview does this, see the helper
        return $this->asJson([
            'html' => Icons::process((string)$value->getHtml()),
        ]);
    }

    private function field(): ?MarkdownField
    {
        $uid = $this->request->getBodyParam('fieldUid');

        if (!is_string($uid) || $uid === '') {
            return null;
        }

        $field = Craft::$app->getFields()->getFieldByUid($uid);

        return $field instanceof MarkdownField ? $field : null;
    }

    /**
     * Switches the request onto the site the editor says it’s on, if the author is
     * allowed to edit it. An unknown or forbidden site is ignored rather than
     * refused, since a preview on the wrong site’s URLs beats no preview at all.
     */
    private function useSite(): void
    {
        $siteId = $this->request->getBodyParam('siteId');

        if (!is_numeric($siteId)) {
            return;
        }

        $site = Craft::$app->getSites()->getSiteById((int)$siteId);

        if ($site !== null && in_array($site->id, Craft::$app->getSites()->getEditableSiteIds(), true)) {
            Craft::$app->getSites()->setCurrentSite($site);
        }
    }

    private function flavour(): string
    {
        $flavour = (string)$this->request->getBodyParam('flavour', MarkdownField::FLAVOUR_GFM_COMMENT);

        // Against the parser flavours, not the ones a field offers: the editor sends
        // the resolved flavour, so a GFM field with its line breaks preserved posts
        // `gfm-comment`. Falling back to plain GFM here would drop the `<br>`s from
        // the preview while the front end kept them
        return in_array($flavour, MarkdownField::parserFlavours(), true)
            ? $flavour
            : MarkdownField::FLAVOUR_GFM_COMMENT;
    }
}
