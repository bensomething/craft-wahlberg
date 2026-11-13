<?php

namespace bensomething\wahlberg\tests\unit;

use bensomething\wahlberg\helpers\MarkdownParser;
use bensomething\wahlberg\helpers\ReferenceTags;
use bensomething\wahlberg\tests\TestCase;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Resolving a reference tag needs elements, which needs a database, which this suite
 * deliberately doesn’t have — see {@see \bensomething\wahlberg\tests\support\Application}.
 *
 * What’s testable without one is the part that decides *which* of the document gets
 * handed to Craft, which is where the bugs live. These tests stand a marker callback
 * in for `Elements::parseRefs()` and assert on what it was and wasn’t given.
 */
class ReferenceTagsTest extends TestCase
{
    /**
     * Stands in for Craft’s ref parser: marks each segment it’s given, and records it.
     *
     * @param list<string> $seen
     * @return callable(string): string
     */
    private function spy(array &$seen): callable
    {
        return static function(string $segment) use (&$seen): string {
            $seen[] = $segment;

            return str_replace('{entry:1:url}', '/resolved', $segment);
        };
    }

    #[TestDox('reference tags outside code get parsed')]
    public function testParsesOutsideCode(): void
    {
        $seen = [];
        $html = ReferenceTags::outsideCode('<p>Go to {entry:1:url} now.</p>', $this->spy($seen));

        self::assertSame('<p>Go to /resolved now.</p>', $html);
        self::assertSame(['<p>Go to {entry:1:url} now.</p>'], $seen);
    }

    #[TestDox('reference tags inside an inline code span are left alone')]
    public function testSkipsInlineCode(): void
    {
        $seen = [];
        $html = ReferenceTags::outsideCode(
            '<p>Write <code>{entry:1:url}</code> to link to {entry:1:url}.</p>',
            $this->spy($seen),
        );

        self::assertSame('<p>Write <code>{entry:1:url}</code> to link to /resolved.</p>', $html);
        self::assertNotContains('<code>{entry:1:url}</code>', $seen);
    }

    #[TestDox('reference tags inside a fenced code block are left alone')]
    public function testSkipsFencedCode(): void
    {
        $seen = [];
        $html = ReferenceTags::outsideCode(
            "<p>{entry:1:url}</p>\n<pre><code class=\"language-twig\">{entry:1:url}\n</code></pre>",
            $this->spy($seen),
        );

        self::assertStringContainsString('<p>/resolved</p>', $html);
        self::assertStringContainsString("<code class=\"language-twig\">{entry:1:url}\n</code>", $html);
    }

    #[TestDox('a document is put back together in one piece')]
    public function testReassemblesEveryPiece(): void
    {
        $html = "<p>a</p><code>b</code><p>c</p><code>d</code><p>e</p>";

        self::assertSame($html, ReferenceTags::outsideCode($html, static fn(string $s): string => $s));
    }

    #[TestDox('code elements back to back don’t swallow what’s between them')]
    public function testHandlesAdjacentCodeElements(): void
    {
        $seen = [];
        $html = ReferenceTags::outsideCode(
            '<code>{entry:1:url}</code>{entry:1:url}<code>{entry:1:url}</code>',
            $this->spy($seen),
        );

        self::assertSame('<code>{entry:1:url}</code>/resolved<code>{entry:1:url}</code>', $html);
        self::assertSame(['{entry:1:url}'], array_filter($seen, static fn(string $s): bool => $s !== ''));
    }

    #[TestDox('an unclosed code element doesn’t swallow the rest of the document')]
    public function testUnclosedCodeElementIsNotTreatedAsCode(): void
    {
        $seen = [];
        $html = ReferenceTags::outsideCode('<p><code>oops</p><p>{entry:1:url}</p>', $this->spy($seen));

        self::assertStringContainsString('/resolved', $html);
    }

    #[TestDox('content with no reference tags is returned untouched, without asking Craft')]
    public function testShortCircuitsWithoutBraces(): void
    {
        // `process()` would reach for the elements service, which this suite has no
        // honest way to provide — so reaching it at all is the failure being tested for
        $html = '<p>Nothing to resolve here.</p>';

        self::assertSame($html, ReferenceTags::process($html));
    }

    /**
     * Resolves Markdown source the way a field with the given flavour would.
     *
     * @param list<string> $seen
     */
    private function resolveMarkdown(string $markdown, array &$seen, string $flavour = 'gfm'): string
    {
        return ReferenceTags::outsideMarkdownCode(
            $markdown,
            static fn(string $md): string => MarkdownParser::process($md, $flavour),
            $this->spy($seen),
        );
    }

    #[TestDox('reference tags in Markdown source get resolved, and the Markdown stays Markdown')]
    public function testMarkdownResolvesWithoutParsing(): void
    {
        $seen = [];
        $markdown = "# Docs\n\n- [Read more]({entry:1:url})\n";

        self::assertSame("# Docs\n\n- [Read more](/resolved)\n", $this->resolveMarkdown($markdown, $seen));
        self::assertSame([$markdown], $seen);
    }

    #[TestDox('reference tags in a Markdown code span are left alone')]
    public function testMarkdownSkipsInlineCode(): void
    {
        $seen = [];

        self::assertSame(
            'Write `{entry:1:url}` to link to /resolved.',
            $this->resolveMarkdown('Write `{entry:1:url}` to link to {entry:1:url}.', $seen),
        );
        self::assertStringNotContainsString('`{entry:1:url}`', $seen[0]);
    }

    #[TestDox('reference tags in fenced, tilde-fenced and indented Markdown code are left alone')]
    public function testMarkdownSkipsCodeBlocks(): void
    {
        $markdown = "{entry:1:url}\n\n```twig\n{entry:1:url}\n```\n\n~~~\n{entry:1:url}\n~~~\n\n    {entry:1:url}\n";
        $seen = [];

        self::assertSame(
            "/resolved\n\n```twig\n{entry:1:url}\n```\n\n~~~\n{entry:1:url}\n~~~\n\n    {entry:1:url}\n",
            $this->resolveMarkdown($markdown, $seen),
        );
    }

    #[TestDox('what counts as code in Markdown source follows the flavour')]
    public function testMarkdownCodeFollowsTheFlavour(): void
    {
        // Traditional Markdown has no tilde fences, so the tag between them is text
        $markdown = "~~~\n{entry:1:url}\n~~~\n";
        $seen = [];

        self::assertSame("~~~\n/resolved\n~~~\n", $this->resolveMarkdown($markdown, $seen, 'original'));
        self::assertSame($markdown, $this->resolveMarkdown($markdown, $seen, 'gfm'));
    }

    #[TestDox('Markdown source with no reference tags is returned untouched, without asking Craft')]
    public function testMarkdownShortCircuitsWithoutBraces(): void
    {
        $markdown = "# Nothing\n\nto resolve here.";

        self::assertSame($markdown, ReferenceTags::processMarkdown($markdown, static fn(string $md): string => $md));
    }
}
