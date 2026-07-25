<?php

namespace bensomething\wahlberg\controllers;

use bensomething\wahlberg\fields\MarkdownField;
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

        // No field to go on? Parse with the requested flavor, but purify regardless —
        // the browser doesn’t get to turn sanitization off
        $value = $field?->normalizeValue($markdown) ?? new MarkdownData(
            $markdown,
            $this->flavor(),
            true,
        );

        return $this->asJson([
            'html' => (string)$value->getHtml(),
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

    private function flavor(): string
    {
        $flavor = (string)$this->request->getBodyParam('flavor', MarkdownField::FLAVOR_GFM);

        return isset(MarkdownField::flavors()[$flavor]) ? $flavor : MarkdownField::FLAVOR_GFM;
    }
}
