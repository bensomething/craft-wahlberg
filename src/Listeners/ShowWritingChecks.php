<?php

declare(strict_types=1);

namespace bensomething\wahlberg\Listeners;

use bensomething\wahlberg\WritingChecks;
use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Field\Contracts\FieldInterface;
use CraftCms\Cms\Field\Data\MarkdownData;
use CraftCms\Cms\Field\Markdown as MarkdownField;
use CraftCms\Cms\Ui\Controls\Markdown as MarkdownControl;
use CraftCms\Cms\Ui\Nodes\Field;
use Throwable;

use function CraftCms\Cms\t;

/**
 * Shows what the writing checks find under a Markdown field, as its warning.
 *
 * The edit form is resolved again each time the element saves or autosaves, so the
 * warning follows the content without any script of Wahlberg's. A save is never
 * blocked: these are things to look at, not errors.
 */
class ShowWritingChecks extends MarkdownFieldListener
{
    public function __construct(
        private readonly WritingChecks $checks,
    ) {}

    protected function apply(Field $node, MarkdownControl $control, FieldInterface $field, string $handle, ?ElementInterface $element): void
    {
        if ($element === null || ! $field instanceof MarkdownField || $this->checks->enabled() === []) {
            return;
        }

        try {
            $value = $element->getFieldValue($handle);
        } catch (Throwable) {
            return;
        }

        if (! $value instanceof MarkdownData) {
            return;
        }

        $warning = WritingChecks::warning($this->checks->check($value, $element->siteId));

        if ($warning === null) {
            return;
        }

        // There's no getter for the warning already set, so it's put back together
        // the way the field layout makes it: the field's own, then the field type's
        $node->warning(implode("<br>\n", array_filter([
            $field->warning ? t($field->warning, category: 'site') : null,
            $field->uiWarning($element),
            $warning,
        ])));
    }
}
