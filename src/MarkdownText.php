<?php

declare(strict_types=1);

namespace bensomething\wahlberg;

use bensomething\wahlberg\Markdown\WahlbergFlavor;
use CraftCms\Cms\Field\Data\MarkdownData;
use CraftCms\Cms\Markdown\Markdown;
use CraftCms\Cms\Support\Facades\Elements;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Block\HtmlBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode;
use League\CommonMark\Extension\CommonMark\Node\Inline\HtmlInline;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\Footnote\Node\FootnoteBackref;
use League\CommonMark\Extension\Footnote\Node\FootnoteContainer;
use League\CommonMark\Extension\Footnote\Node\FootnoteRef;
use League\CommonMark\Node\Block\AbstractBlock;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\Inline\AbstractStringContainer;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Node\NodeIterator;
use League\CommonMark\Node\RawMarkupContainerInterface;
use League\CommonMark\Node\StringContainerHelper;

/**
 * What the template helpers know about some Markdown: its headings, its text, how
 * long it takes to read, and its source with reference tags resolved.
 *
 * Each takes a Markdown field's value or a string. A field value is parsed in its
 * field's flavour, a string in the Wahlberg flavour.
 *
 * @phpstan-type TocEntry array{level: int, text: string, id: string}
 */
class MarkdownText
{
    /** A comfortable reading pace for prose on a screen. */
    public const int WORDS_PER_MINUTE = 200;

    /**
     * A `<code>` element and its contents. Code elements can't nest, so the lazy
     * quantifier stops at the right closing tag.
     */
    private const string CODE = '/<code\b[^>]*>.*?<\/code>/is';

    public function __construct(
        private readonly Markdown $markdown,
    ) {}

    /**
     * The headings, in order, with the ids the Wahlberg flavour gives them, so a
     * table of contents links into a field on that flavour. Always parsed in it, so
     * the ids are the same whichever flavour the field uses.
     *
     * @return list<TocEntry>
     */
    public function toc(MarkdownData|string|null $markdown, int $minLevel = 1, int $maxLevel = 6): array
    {
        $toc = [];

        $this->walk(self::source($markdown), WahlbergFlavor::NAME, function (Document $document) use (&$toc, $minLevel, $maxLevel): void {
            foreach ($document->iterator(NodeIterator::FLAG_BLOCKS_ONLY) as $node) {
                if (! $node instanceof Heading || $node->getLevel() < $minLevel || $node->getLevel() > $maxLevel) {
                    continue;
                }

                $toc[] = [
                    'level' => $node->getLevel(),
                    'text' => trim(StringContainerHelper::getChildText($node, [RawMarkupContainerInterface::class])),
                    'id' => (string) $node->data->get('attributes/id', ''),
                ];
            }
        });

        return $toc;
    }

    /**
     * The text, without Markdown: blocks separated by a blank line, code kept as
     * typed. Images, raw HTML and footnote markers go, since none of them is prose.
     */
    public function plainText(MarkdownData|string|null $markdown): string
    {
        $text = '';

        $this->walk(self::source($markdown), self::flavour($markdown), function (Document $document) use (&$text): void {
            $walker = $document->walker();

            while ($event = $walker->next()) {
                $node = $event->getNode();

                if (! $event->isEntering()) {
                    if ($node instanceof AbstractBlock && ! $node instanceof Document) {
                        $text .= "\n\n";
                    }

                    continue;
                }

                if (
                    $node instanceof Image
                    || $node instanceof HtmlBlock
                    || $node instanceof HtmlInline
                    || $node instanceof FootnoteRef
                    || $node instanceof FootnoteBackref
                    || $node instanceof FootnoteContainer
                ) {
                    $walker->resumeAt($node, false);

                    continue;
                }

                if ($node instanceof FencedCode || $node instanceof IndentedCode) {
                    $text .= rtrim($node->getLiteral());
                } elseif ($node instanceof AbstractStringContainer) {
                    $text .= $node->getLiteral();
                } elseif ($node instanceof Newline) {
                    $text .= ' ';
                }
            }
        });

        // Tidy what the walk leaves: runs of spaces inside a line, and the gaps that
        // nested blocks (a list in a quote) stack up
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/ *\n */u', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * The start of the text, on one line, cut at a word boundary and ending with the
     * suffix if anything was cut.
     */
    public function excerpt(MarkdownData|string|null $markdown, int $length = 160, string $suffix = '…'): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $this->plainText($markdown)) ?? '');

        if (mb_strlen($text) <= $length) {
            return $text;
        }

        $cut = mb_substr($text, 0, max(0, $length - mb_strlen($suffix)) + 1);
        $space = mb_strrpos($cut, ' ');

        // Back to the last whole word, unless the first word alone is too long
        $cut = $space !== false ? mb_substr($cut, 0, $space) : mb_substr($cut, 0, -1);

        return rtrim($cut, " \t,;:.-–—").$suffix;
    }

    /** Minutes to read, rounded up. Anything with words in it takes at least one. */
    public function readingTime(MarkdownData|string|null $markdown, int $wordsPerMinute = self::WORDS_PER_MINUTE): int
    {
        $words = preg_split('/\s+/u', $this->plainText($markdown), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return $words === [] ? 0 : (int) max(1, ceil(count($words) / max(1, $wordsPerMinute)));
    }

    /**
     * The Markdown source with its reference tags resolved and nothing else changed,
     * for output that should stay Markdown, like an `llms.txt`. A tag inside code is
     * left as typed, as it is in the field's HTML.
     *
     * What counts as code is asked of the parser rather than worked out from the
     * source, which would mean re-implementing fence and indent rules: each tag is
     * swapped for a token no flavour touches, the result is parsed, and a token that
     * comes out inside a `<code>` element was a tag in code.
     */
    public function parseRefs(MarkdownData|string|null $markdown, ?int $siteId = null): string
    {
        $source = self::source($markdown);

        // Every reference tag has one, so this skips the parse for most content
        if (! str_contains($source, '{')) {
            return $source;
        }

        $resolve = fn (string $text): string => Elements::parseRefs($text, $siteId);

        // Broader than Craft's own pattern on purpose: only what's matched can be
        // protected, so matching too much costs nothing
        if (! preg_match_all('/\{[\w\\\\]+:[^}]+\}/', $source, $matches, PREG_OFFSET_CAPTURE)) {
            return $resolve($source);
        }

        // Lowercase letters and digits, which no flavour does anything with. The
        // closing letter keeps token 1 from being a prefix of token 10
        $prefix = 'wahlbergref'.bin2hex(random_bytes(4)).'n';
        $token = fn (int $i): string => $prefix.$i.'z';

        $html = $this->markdown->parse(self::replace($source, $matches[0], fn (int $i) => $token($i)), self::flavour($markdown));

        $inCode = [];

        if (preg_match_all(self::CODE, $html, $code)) {
            preg_match_all('/'.$prefix.'(\d+)z/', implode('', $code[0]), $found);
            $inCode = array_flip(array_map('intval', $found[1]));
        }

        if ($inCode === []) {
            return $resolve($source);
        }

        $protected = self::replace($source, $matches[0], fn (int $i, string $tag) => isset($inCode[$i]) ? $token($i) : $tag);
        $restore = [];

        foreach (array_keys($inCode) as $i) {
            $restore[$token($i)] = $matches[0][$i][0];
        }

        return strtr($resolve($protected), $restore);
    }

    /** @param  callable(Document): void  $inspect */
    private function walk(string $source, string $flavour, callable $inspect): void
    {
        if (trim($source) !== '') {
            $this->markdown->transform($source, $inspect, $flavour);
        }
    }

    private static function source(MarkdownData|string|null $markdown): string
    {
        return $markdown instanceof MarkdownData ? $markdown->getRaw() : (string) $markdown;
    }

    private static function flavour(MarkdownData|string|null $markdown): string
    {
        return $markdown instanceof MarkdownData ? $markdown->getFlavor() : WahlbergFlavor::NAME;
    }

    /**
     * The string with each match swapped for what the callback returns.
     *
     * @param  list<array{string, int}>  $matches  From preg_match_all() with offsets
     * @param  callable(int, string): string  $replace
     */
    private static function replace(string $string, array $matches, callable $replace): string
    {
        $out = '';
        $at = 0;

        foreach ($matches as $i => [$tag, $offset]) {
            $out .= substr($string, $at, $offset - $at).$replace($i, $tag);
            $at = $offset + strlen($tag);
        }

        return $out.substr($string, $at);
    }
}
