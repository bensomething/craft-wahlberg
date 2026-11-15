<?php

declare(strict_types=1);

namespace bensomething\wahlberg\Listeners;

use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Field\Contracts\FieldInterface;
use CraftCms\Cms\FieldLayout\Events\FieldLayoutUiResolving;
use CraftCms\Cms\FieldLayout\FieldLayout;
use CraftCms\Cms\Ui\Contracts\Node;
use CraftCms\Cms\Ui\Controls\Markdown as MarkdownControl;
use CraftCms\Cms\Ui\Nodes\Field;

/**
 * Finds each native Markdown field in a field layout's UI and hands it over.
 *
 * A field's control is found by its path, which a field layout always sets to
 * `['fields', <handle>]`, with the handle the layout gives the field.
 */
abstract class MarkdownFieldListener
{
    public function handle(FieldLayoutUiResolving $event): void
    {
        $this->walk($event->ui->nodes(), $event->fieldLayout, $event->element);
    }

    /** @param  string  $handle  The field's handle in this layout */
    abstract protected function apply(Field $node, MarkdownControl $control, FieldInterface $field, string $handle, ?ElementInterface $element): void;

    /** @param  array<Node>  $nodes */
    private function walk(array $nodes, FieldLayout $layout, ?ElementInterface $element): void
    {
        foreach ($nodes as $node) {
            $control = $node->getControl();

            if ($node instanceof Field && $control instanceof MarkdownControl) {
                $path = (array) $control->path();
                $handle = $path[1] ?? null;
                $field = ($path[0] ?? null) === 'fields' && is_string($handle) ? $layout->getFieldByHandle($handle) : null;

                if ($field) {
                    $this->apply($node, $control, $field, $handle, $element);
                }
            }

            $this->walk($node->children(), $layout, $element);
        }
    }
}
