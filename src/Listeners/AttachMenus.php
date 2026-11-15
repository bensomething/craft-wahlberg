<?php

declare(strict_types=1);

namespace bensomething\wahlberg\Listeners;

use bensomething\wahlberg\EntryLinks;
use bensomething\wahlberg\Snippets;
use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\FieldLayout\Events\FieldLayoutUiResolving;
use CraftCms\Cms\FieldLayout\FieldLayout;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\Ui\Contracts\Node;
use CraftCms\Cms\Ui\Controls\Markdown as MarkdownControl;

use function CraftCms\Cms\t;

/**
 * Gives every native Markdown field a field layout renders its menus: the snippets
 * one (`/` by default) and, where the field's link settings allow entry links, the
 * `@` one for linking to entries.
 *
 * Craft's Markdown control already carries a text expander, it just never gives it
 * any triggers. A field's control is found by its path, which a field layout always
 * sets to `['fields', <handle>]`.
 */
class AttachMenus
{
    public const string ENTRY_LINK_TRIGGER = '@';

    public function __construct(
        private readonly Snippets $snippets,
    ) {}

    public function handle(FieldLayoutUiResolving $event): void
    {
        $this->walk($event->ui->nodes(), $event->fieldLayout, $event->element);
    }

    /** @param  array<Node>  $nodes */
    private function walk(array $nodes, FieldLayout $layout, ?ElementInterface $element): void
    {
        foreach ($nodes as $node) {
            $control = $node->getControl();

            if ($control instanceof MarkdownControl) {
                $this->attach($control, $layout, $element);
            }

            $this->walk($node->children(), $layout, $element);
        }
    }

    private function attach(MarkdownControl $control, FieldLayout $layout, ?ElementInterface $element): void
    {
        $path = (array) $control->path();

        if (($path[0] ?? null) !== 'fields' || ! is_string($path[1] ?? null)) {
            return;
        }

        $field = $layout->getFieldByHandle($path[1]);

        if (! $field) {
            return;
        }

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
