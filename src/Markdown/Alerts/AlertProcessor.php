<?php

declare(strict_types=1);

namespace bensomething\wahlberg\Markdown\Alerts;

use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\Node\Block\BlockQuote;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\NodeIterator;

/**
 * Turns a quote into an alert when the first line of its first paragraph is a
 * marker and nothing else, the way GitHub reads them:
 *
 *     > [!WARNING]
 *     > Mind the gap.
 *
 * Case doesn't matter. A marker with text after it on the same line stays a quote.
 */
final class AlertProcessor
{
    private const string MARKER = '/^\[!(note|tip|important|warning|caution)\]\s*$/i';

    public function __invoke(DocumentParsedEvent $event): void
    {
        // Collected first, since converting one moves nodes around under the iterator
        $quotes = [];

        foreach ($event->getDocument()->iterator(NodeIterator::FLAG_BLOCKS_ONLY) as $node) {
            if ($node instanceof BlockQuote) {
                $quotes[] = $node;
            }
        }

        foreach ($quotes as $quote) {
            $this->convert($quote);
        }
    }

    private function convert(BlockQuote $quote): void
    {
        $paragraph = $quote->firstChild();

        if (! $paragraph instanceof Paragraph) {
            return;
        }

        // The brackets can come through as more than one text node, so the first
        // line is read across however many there are
        $line = [];
        $marker = '';
        $break = null;

        foreach ($paragraph->children() as $child) {
            if ($child instanceof Newline) {
                $break = $child;
                break;
            }

            if (! $child instanceof Text) {
                return;
            }

            $line[] = $child;
            $marker .= $child->getLiteral();
        }

        if (! preg_match(self::MARKER, $marker, $match)) {
            return;
        }

        foreach ($line as $node) {
            $node->detach();
        }

        $break?->detach();

        // A marker on its own, with the text in paragraphs after it
        if (! $paragraph->hasChildren()) {
            $paragraph->detach();
        }

        /** @var value-of<Alert::TYPES> $type */
        $type = strtolower($match[1]);
        $alert = new Alert($type);

        foreach ($quote->children() as $child) {
            $alert->appendChild($child);
        }

        $quote->replaceWith($alert);
    }
}
