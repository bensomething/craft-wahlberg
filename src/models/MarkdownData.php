<?php

namespace bensomething\wahlberg\models;

use bensomething\wahlberg\helpers\Purifier;
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
        private readonly string $flavor = 'gfm',
        private readonly bool $purify = true,
        private readonly ?string $purifierConfig = null,
    ) {
    }

    public function __toString(): string
    {
        return $this->markdown;
    }

    /**
     * The raw Markdown, as the author typed it. Never purified — this is the source.
     */
    public function getRaw(): string
    {
        return $this->markdown;
    }

    /**
     * The Markdown flavor the field is configured to parse with.
     */
    public function getFlavor(): string
    {
        return $this->flavor;
    }

    /**
     * Whether the parsed HTML gets run through HTML Purifier.
     */
    public function getPurified(): bool
    {
        return $this->purify;
    }

    /**
     * The parsed HTML, ready to output.
     */
    public function getHtml(): Markup
    {
        return Template::raw($this->parse());
    }

    /**
     * The parsed HTML with tags stripped — handy for meta descriptions and excerpts.
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

        $html = Markdown::process($this->markdown, $this->flavor);

        if ($this->purify) {
            $html = Purifier::process($html, $this->purifierConfig);
        }

        return $this->parsed = $html;
    }
}
