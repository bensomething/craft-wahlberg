<?php

declare(strict_types=1);

namespace bensomething\wahlberg\Listeners;

use bensomething\wahlberg\EntryLinks;
use bensomething\wahlberg\Snippets;
use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Field\Contracts\FieldInterface;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\Ui\Controls\Markdown as MarkdownControl;
use CraftCms\Cms\Ui\Nodes\Field;

use function CraftCms\Cms\t;

/**
 * Gives every native Markdown field a field layout renders its menus: the snippets
 * one (`/` by default) and, where the field's link settings allow entry links, the
 * `@` one for linking to entries.
 *
 * Craft's Markdown control already carries a text expander, it just never gives it
 * any triggers.
 */
class AttachMenus extends MarkdownFieldListener
{
    public const string ENTRY_LINK_TRIGGER = '@';

    public function __construct(
        private readonly Snippets $snippets,
    ) {}

    protected function apply(Field $node, MarkdownControl $control, FieldInterface $field, string $handle, ?ElementInterface $element): void
    {
        $triggers = [];
        $options = $this->snippets->optionsFor($field);

        if ($options !== []) {
            $triggers[] = [
                'trigger' => $this->snippets->trigger(),
                'boundary' => 'whitespace',
                'label' => t('Snippets', category: 'wahlberg'),
                'options' => $options,
            ];
        }

        if (EntryLinks::enabledFor($field)) {
            // Searched as the author types, by the field's link settings. Entries in
            // the site being edited, so the link goes to that site's version
            $triggers[] = [
                'trigger' => self::ENTRY_LINK_TRIGGER,
                'boundary' => 'whitespace',
                'label' => t('Entries', category: 'wahlberg'),
                'source' => route('wahlberg.entries', [
                    'field' => $field->id,
                    'site' => $element->siteId ?? Sites::getPrimarySite()->id,
                ]),
            ];
        }

        // There's no getter for triggers already set, so this replaces them. Core
        // sets none on the Markdown field today.
        if ($triggers !== []) {
            $control->textExpanderTriggers($triggers);
        }
    }
}
