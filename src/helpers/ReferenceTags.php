<?php

namespace bensomething\wahlberg\helpers;

use Craft;

/**
 * Resolves Craft’s [reference tags](https://craftcms.com/docs/5.x/system/reference-tags.html)
 * like `{entry:123:url}` and `{asset:5:title}` in parsed Markdown.
 *
 * This runs over the *parsed HTML* rather than the Markdown source, which is what
 * lets code be left alone: by the time it runs, the parser has already decided what
 * counts as code and wrapped it in a `<code>` element. Working out the same thing
 * from Markdown source means re-implementing fence, tilde and indent rules, and
 * getting them subtly wrong.
 *
 * Nothing is lost by waiting, because the parser leaves reference tags intact
 * wherever they can usefully appear, link and image targets included, where
 * `[Read more]({entry:123:url})` parses to `<a href="{entry:123:url}">`.
 */
abstract class ReferenceTags
{
    /**
     * A `<code>` element and its contents. Code elements can’t nest, so the lazy
     * quantifier stops at the right closing tag.
     */
    private const CODE_PATTERN = '/(<code\b[^>]*>.*?<\/code>)/is';

    /**
     * Resolves the reference tags in some parsed HTML, leaving any inside a `<code>`
     * element as the author typed them.
     *
     * @param int|null $siteId The site to resolve references against. Null uses the
     * current site, which is Craft’s own default.
     */
    public static function process(string $html, ?int $siteId = null): string
    {
        // Every reference tag has one, so this skips a split and a service call
        // on the vast majority of content, which has none at all
        if (!str_contains($html, '{')) {
            return $html;
        }

        return self::outsideCode(
            $html,
            static fn(string $segment): string => Craft::$app->getElements()->parseRefs($segment, $siteId),
        );
    }

    /**
     * Runs a callback over the parts of some HTML that aren’t inside a `<code>`
     * element, and puts the result back together.
     *
     * @param callable(string): string $callback
     */
    public static function outsideCode(string $html, callable $callback): string
    {
        $segments = preg_split(self::CODE_PATTERN, $html, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($segments === false) {
            // PCRE gave up, on a pathological document or an unclosed `<code>`. Parse
            // the lot rather than none of it: a tag resolved inside a code sample is a
            // cosmetic surprise, a page of unresolved ones is a page of broken links.
            return $callback($html);
        }

        // The pattern captures, so the pieces alternate: outside, code, outside, code…
        foreach ($segments as $i => $segment) {
            if ($i % 2 === 0 && $segment !== '') {
                $segments[$i] = $callback($segment);
            }
        }

        return implode('', $segments);
    }
}
