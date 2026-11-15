<?php

declare(strict_types=1);

namespace bensomething\wahlberg\Twig;

use bensomething\wahlberg\MarkdownText;
use CraftCms\Cms\Field\Data\MarkdownData;
use Stringable;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Template helpers for Markdown, for a Markdown field's value or a string:
 *
 *     {% for heading in entry.body|toc(2, 3) %}
 *         <a href="#{{ heading.id }}">{{ heading.text }}</a>
 *     {% endfor %}
 *
 *     <meta name="description" content="{{ entry.body|excerpt(155) }}">
 *     {{ entry.body|readingTime }} min read
 *     {{ entry.body|plainText }}
 *     {{ entry.body|parseMarkdownRefs }}
 *
 * The services are resolved when a filter runs, not when the extension's made.
 */
class WahlbergExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        $text = fn (): MarkdownText => app(MarkdownText::class);

        return [
            new TwigFilter('toc', fn (mixed $markdown, int $minLevel = 1, int $maxLevel = 6) => $text()->toc(self::value($markdown), $minLevel, $maxLevel)),
            new TwigFilter('plainText', fn (mixed $markdown) => $text()->plainText(self::value($markdown))),
            new TwigFilter('excerpt', fn (mixed $markdown, int $length = 160, string $suffix = '…') => $text()->excerpt(self::value($markdown), $length, $suffix)),
            new TwigFilter('readingTime', fn (mixed $markdown, int $wordsPerMinute = MarkdownText::WORDS_PER_MINUTE) => $text()->readingTime(self::value($markdown), $wordsPerMinute)),
            new TwigFilter('parseMarkdownRefs', fn (mixed $markdown, ?int $siteId = null) => $text()->parseRefs(self::value($markdown), $siteId)),
        ];
    }

    /** A field value as it is, anything else as its string. */
    private static function value(mixed $markdown): MarkdownData|string|null
    {
        if ($markdown === null || $markdown instanceof MarkdownData) {
            return $markdown;
        }

        return is_scalar($markdown) || $markdown instanceof Stringable ? (string) $markdown : null;
    }
}
