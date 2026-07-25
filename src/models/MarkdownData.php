<?php

namespace bensomething\wahlberg\models;

use bensomething\wahlberg\helpers\Purifier;
use bensomething\wahlberg\helpers\ReferenceTags;
use craft\helpers\Markdown;
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

    public function __construct(
        private readonly string $markdown,
        private readonly string $flavour = 'gfm-comment',
        private readonly bool $purify = true,
        private readonly ?string $purifierConfig = null,
        private readonly bool $parseRefs = true,
        private readonly ?int $siteId = null,
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

        $html = Markdown::process($this->markdown, $this->flavour);

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
}
