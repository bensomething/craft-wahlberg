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
     * Resolves the reference tags in some Markdown *source*, leaving the Markdown
     * itself unparsed and any tag inside code as the author typed it.
     *
     * @param callable(string): string $parse Turns Markdown into HTML the way the
     * field renders it, so what counts as code here is exactly what counts as code
     * in the field’s HTML.
     * @param int|null $siteId The site to resolve references against. Null uses the
     * current site, which is Craft’s own default.
     */
    public static function processMarkdown(string $markdown, callable $parse, ?int $siteId = null): string
    {
        if (!str_contains($markdown, '{')) {
            return $markdown;
        }

        return self::outsideMarkdownCode(
            $markdown,
            $parse,
            static fn(string $source): string => Craft::$app->getElements()->parseRefs($source, $siteId),
        );
    }

    /**
     * Runs a callback over some Markdown source with any reference tag inside code
     * hidden from it, and puts those tags back afterwards.
     *
     * Working out what’s code from the source would mean re-implementing fence,
     * tilde and indent rules, per flavour. So the parser is asked instead: each
     * candidate tag is swapped for a token the parser won’t touch, the result is
     * parsed, and a token that comes out inside a `<code>` element marks a tag that
     * was in code.
     *
     * @param callable(string): string $parse
     * @param callable(string): string $callback
     */
    public static function outsideMarkdownCode(string $markdown, callable $parse, callable $callback): string
    {
        // Broader than Craft’s own pattern on purpose. Only what’s matched can be
        // protected, so matching too much costs nothing and matching too little
        // would let a tag in code be resolved
        if (!preg_match_all('/\{[\w\\\\]+:[^}]+\}/', $markdown, $matches, PREG_OFFSET_CAPTURE)) {
            return $callback($markdown);
        }

        // Lowercase letters and digits only, which no flavour does anything with. The
        // closing letter keeps token 1 from being a prefix of token 10
        $prefix = 'wahlbergref' . bin2hex(random_bytes(4)) . 'n';
        $token = static fn(int $i): string => $prefix . $i . 'z';

        $tokenized = self::replaceMatches($markdown, $matches[0], static fn(int $i): string => $token($i));
        $html = $parse($tokenized);

        $inCode = [];
        if (preg_match_all(self::CODE_PATTERN, $html, $code)) {
            preg_match_all('/' . $prefix . '(\d+)z/', implode('', $code[0]), $found);
            $inCode = array_flip(array_map('intval', $found[1]));
        }

        if (!$inCode) {
            return $callback($markdown);
        }

        $protected = self::replaceMatches(
            $markdown,
            $matches[0],
            static fn(int $i, string $tag): string => isset($inCode[$i]) ? $token($i) : $tag,
        );

        $restore = [];
        foreach (array_keys($inCode) as $i) {
            $restore[$token($i)] = $matches[0][$i][0];
        }

        return strtr($callback($protected), $restore);
    }

    /**
     * Rebuilds a string with each match swapped for whatever the callback returns.
     *
     * @param list<array{string, int}> $matches From `preg_match_all()` with offsets.
     * @param callable(int, string): string $replace
     */
    private static function replaceMatches(string $str, array $matches, callable $replace): string
    {
        $out = '';
        $pos = 0;

        foreach ($matches as $i => [$tag, $offset]) {
            $out .= substr($str, $pos, $offset - $pos) . $replace($i, $tag);
            $pos = $offset + strlen($tag);
        }

        return $out . substr($str, $pos);
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
