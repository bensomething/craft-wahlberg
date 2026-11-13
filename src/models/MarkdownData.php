<?php

namespace bensomething\wahlberg\models;

use bensomething\wahlberg\helpers\MarkdownParser;
use bensomething\wahlberg\helpers\Purifier;
use bensomething\wahlberg\helpers\ReferenceTags;
use craft\helpers\Html;
use craft\helpers\Template;
use JsonSerializable;
use Stringable;
use Twig\Markup;

/**
 * The value of a Markdown field.
 *
 * Casting to a string returns the raw Markdown, so `{{ entry.body|md }}` keeps
 * working alongside `{{ entry.body.html }}`.
 */
class MarkdownData implements Stringable, JsonSerializable
{
    private ?string $parsed = null;

    private ?string $resolved = null;

    public function __construct(
        private readonly string $markdown,
        private readonly string $flavour = 'gfm-comment',
        private readonly bool $purify = true,
        private readonly ?string $purifierConfig = null,
        private readonly bool $parseRefs = true,
        private readonly ?int $siteId = null,
        private readonly bool $inlineOnly = false,
        private readonly bool $encodeHtml = false,
    ) {
    }

    public function __toString(): string
    {
        return $this->markdown;
    }

    /**
     * The raw Markdown, as the author typed it. Never purified: this is the source.
     */
    public function getRaw(): string
    {
        return $this->markdown;
    }

    /**
     * The Markdown with its reference tags resolved, but not parsed: what to output
     * where Markdown is wanted rather than HTML, like an `llms.txt`. Tags inside code
     * are left as typed, the same as in the HTML.
     *
     * Neither purified nor encoded, since there’s no HTML yet to do either to. It’s a
     * plain string, so Twig escapes it in an HTML template, which is right for HTML
     * and wrong for a text file. Pipe it through `|raw` in the text file.
     */
    public function getMarkdown(): string
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        if (!$this->parseRefs) {
            return $this->resolved = $this->markdown;
        }

        return $this->resolved = ReferenceTags::processMarkdown(
            $this->markdown,
            fn(string $markdown): string => $this->render($markdown),
            $this->siteId,
        );
    }

    /**
     * The resolved Markdown flavour this was parsed with, so a GFM field with its
     * line breaks preserved reports `gfm-comment`. Safe to hand to `|md`.
     */
    public function getFlavour(): string
    {
        return $this->flavour;
    }

    /**
     * Whether the parsed HTML gets run through HTML Purifier.
     */
    public function getPurified(): bool
    {
        return $this->purify;
    }

    /**
     * Whether reference tags in the parsed HTML get resolved.
     */
    public function getParseRefs(): bool
    {
        return $this->parseRefs;
    }

    /**
     * The HTML Purifier config file the parsed HTML gets purified with, if any.
     */
    public function getPurifierConfig(): ?string
    {
        return $this->purifierConfig;
    }

    /**
     * The site reference tags resolve against, if it was pinned to one.
     */
    public function getSiteId(): ?int
    {
        return $this->siteId;
    }

    /**
     * Whether the Markdown renders as inline content, without the wrapping `<p>`.
     */
    public function getInlineOnly(): bool
    {
        return $this->inlineOnly;
    }

    /**
     * Whether HTML gets encoded before parsing, so anything the author typed as a
     * tag comes out as text.
     */
    public function getEncodeHtml(): bool
    {
        return $this->encodeHtml;
    }

    /**
     * The parsed HTML, ready to output.
     */
    public function getHtml(): Markup
    {
        return Template::raw($this->parse());
    }

    /**
     * The parsed HTML with tags stripped, handy for meta descriptions and excerpts.
     */
    public function getText(): string
    {
        $text = html_entity_decode(strip_tags($this->parse()), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string)preg_replace('/\s+/u', ' ', $text));
    }

    public function jsonSerialize(): string
    {
        return $this->markdown;
    }

    private function parse(): string
    {
        if ($this->parsed !== null) {
            return $this->parsed;
        }

        $html = $this->render($this->markdown);

        // Before purification, not after: whatever a reference tag resolves to gets
        // sanitized along with everything else
        if ($this->parseRefs) {
            $html = ReferenceTags::process($html, $this->siteId);
        }

        if ($this->purify) {
            $html = Purifier::process($html, $this->purifierConfig);
        }

        return $this->parsed = $html;
    }

    /**
     * Turns Markdown into HTML with this field’s parser settings, before reference
     * tags or purification.
     */
    private function render(string $markdown): string
    {
        // Before the parser sees it, so a tag reaches the page as text. Purifying
        // would drop it instead; encoding shows it
        if ($this->encodeHtml) {
            $markdown = Html::encode($markdown);
        }

        return $this->inlineOnly
            ? MarkdownParser::processParagraph($markdown, $this->flavour)
            : MarkdownParser::process($markdown, $this->flavour);
    }
}
