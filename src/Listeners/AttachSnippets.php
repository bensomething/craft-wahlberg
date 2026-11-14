<?php

declare(strict_types=1);

namespace bensomething\wahlberg\Listeners;

use bensomething\wahlberg\Snippets;
use CraftCms\Cms\FieldLayout\Events\FieldLayoutUiResolving;
use CraftCms\Cms\FieldLayout\FieldLayout;
use CraftCms\Cms\Ui\Contracts\Node;
use CraftCms\Cms\Ui\Controls\Markdown as MarkdownControl;

use function CraftCms\Cms\t;

/**
 * Puts the snippets on every native Markdown field a field layout renders.
 *
 * Craft's Markdown control already carries a text expander, it just never gives it
 * any triggers. This gives it one: the `/` menu, listing the snippets the field
 * offers. A field's control is found by its path, which a field layout always sets
 * to `['fields', <handle>]`.
 */
class AttachSnippets
{
    public function __construct(
        private readonly Snippets $snippets,
    ) {}

    public function handle(FieldLayoutUiResolving $event): void
    {
        if ($this->snippets->all() === []) {
            return;
        }

        $this->walk($event->ui->nodes(), $event->fieldLayout);
    }

    /** @param  array<Node>  $nodes */
    private function walk(array $nodes, FieldLayout $layout): void
    {
        foreach ($nodes as $node) {
            $control = $node->getControl();

            if ($control instanceof MarkdownControl) {
                $this->attach($control, $layout);
            }

            $this->walk($node->children(), $layout);
        }
    }

    private function attach(MarkdownControl $control, FieldLayout $layout): void
    {
        $path = (array) $control->path();

        if (($path[0] ?? null) !== 'fields' || ! is_string($path[1] ?? null)) {
            return;
        }

        $field = $layout->getFieldByHandle($path[1]);
        $options = $field ? $this->snippets->optionsFor($field) : [];

        if ($options === []) {
            return;
        }

        // There's no getter for triggers already set, so this replaces them. Core
        // sets none on the Markdown field today.
        $control->textExpanderTriggers([[
            'trigger' => $this->snippets->trigger(),
            'boundary' => 'whitespace',
            'label' => t('Snippets', category: 'wahlberg'),
            'options' => $options,
        ]]);
    }
}
