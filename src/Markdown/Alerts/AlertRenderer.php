<?php

declare(strict_types=1);

namespace bensomething\wahlberg\Markdown\Alerts;

use InvalidArgumentException;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\HtmlElement;
use League\CommonMark\Util\Xml;

use function CraftCms\Cms\t;

/**
 * GitHub's markup, less its icons, so CSS written for GitHub's alerts (or for
 * GitHub-style Markdown generally) works as it is:
 *
 *     <div class="markdown-alert markdown-alert-warning">
 *     <p class="markdown-alert-title">Warning</p>
 *     <p>Mind the gap.</p>
 *     </div>
 *
 * The title is translated in the `site` category, so a site in another language
 * can give it its own word.
 */
final class AlertRenderer implements NodeRendererInterface
{
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): HtmlElement
    {
        if (! $node instanceof Alert) {
            throw new InvalidArgumentException('Incompatible node type: '.$node::class);
        }

        $title = match ($node->type) {
            'note' => t('Note', category: 'site'),
            'tip' => t('Tip', category: 'site'),
            'important' => t('Important', category: 'site'),
            'warning' => t('Warning', category: 'site'),
            'caution' => t('Caution', category: 'site'),
        };

        $separator = $childRenderer->getBlockSeparator();
        $inner = $childRenderer->renderNodes($node->children());

        return new HtmlElement(
            'div',
            ['class' => "markdown-alert markdown-alert-{$node->type}"],
            $separator
                .new HtmlElement('p', ['class' => 'markdown-alert-title'], Xml::escape($title))
                .($inner !== '' ? $separator.$inner : '')
                .$separator,
        );
    }
}
