<?php

namespace bensomething\wahlberg\tests\unit;

use bensomething\wahlberg\models\MarkdownData;
use bensomething\wahlberg\tests\TestCase;
use PHPUnit\Framework\Attributes\TestDox;
use Twig\Markup;

class MarkdownDataTest extends TestCase
{
    #[TestDox('casting to a string gives back the Markdown source, so |md still works')]
    public function testStringableReturnsSource(): void
    {
        $markdown = "# Heading\n\n- one\n- two\n";
        $data = new MarkdownData($markdown);

        self::assertSame($markdown, (string)$data);
        self::assertSame($markdown, $data->getRaw());
        self::assertSame($markdown, $data->jsonSerialize());
    }

    #[TestDox('the source survives purification untouched')]
    public function testPurifyingLeavesTheSourceAlone(): void
    {
        $markdown = "Autolink <https://example.com> and a fence:\n\n```\n<?php echo 1 & 2; ?>\n```\n";
        $data = new MarkdownData($markdown, 'gfm', true);

        // Parse first, so any mutation of the source would have happened by now
        $data->getHtml();

        self::assertSame($markdown, $data->getRaw());
    }

    public function testHtmlIsMarkupSoTwigWontEscapeIt(): void
    {
        $html = (new MarkdownData('**bold**'))->getHtml();

        self::assertInstanceOf(Markup::class, $html);
        self::assertStringContainsString('<strong>bold</strong>', (string)$html);
    }

    #[TestDox('the flavor decides what gets parsed')]
    public function testFlavorIsHonoured(): void
    {
        $markdown = "```\ncode\n```";

        // Fenced code blocks are a GitHub extension. Traditional Markdown has no idea,
        // and reads the backticks as an inline code span instead
        self::assertStringContainsString('<pre>', (string)(new MarkdownData($markdown, 'gfm'))->getHtml());
        self::assertStringNotContainsString('<pre>', (string)(new MarkdownData($markdown, 'original'))->getHtml());

        self::assertSame('original', (new MarkdownData($markdown, 'original'))->getFlavor());
    }

    #[TestDox('gfm-comment turns single newlines into breaks')]
    public function testGfmCommentPreservesLineBreaks(): void
    {
        $markdown = "one\ntwo";

        self::assertStringContainsString('<br', (string)(new MarkdownData($markdown, 'gfm-comment'))->getHtml());
        self::assertStringNotContainsString('<br', (string)(new MarkdownData($markdown, 'gfm'))->getHtml());
    }

    public function testTextStripsTagsAndCollapsesWhitespace(): void
    {
        $data = new MarkdownData("# Heading\n\nSome **bold**   text.\n\n- one\n- two\n");

        self::assertSame('Heading Some bold text. one two', $data->getText());
    }

    public function testTextDecodesEntities(): void
    {
        self::assertSame('Fish & chips', (new MarkdownData('Fish & chips'))->getText());
    }

    #[TestDox('purification strips scripts but leaves embeds alone')]
    public function testPurifyUsesCraftsDefaults(): void
    {
        $markdown = 'Hello <script>alert(1)</script> <iframe src="https://www.youtube.com/embed/abc"></iframe>';
        $html = (string)(new MarkdownData($markdown, 'gfm', true))->getHtml();

        self::assertStringNotContainsString('<script', $html);
        self::assertStringContainsString('youtube.com/embed/abc', $html);
    }

    public function testPurifyCanBeTurnedOff(): void
    {
        $html = (string)(new MarkdownData('<script>alert(1)</script>', 'gfm', false))->getHtml();

        self::assertStringContainsString('<script>alert(1)</script>', $html);
    }

    #[TestDox('purification does not mangle code fences or autolinks')]
    public function testPurifyLeavesCodeAndAutolinksIntact(): void
    {
        $markdown = "<https://example.com>\n\n```\n<?php echo 'hi'; ?>\n```\n";
        $html = (string)(new MarkdownData($markdown, 'gfm', true))->getHtml();

        self::assertStringContainsString('https://example.com', $html);
        self::assertStringContainsString('&lt;?php', $html);
    }

    #[TestDox('the parsed HTML is only built once')]
    public function testParsingIsMemoized(): void
    {
        $data = new MarkdownData('# Heading');

        self::assertSame((string)$data->getHtml(), (string)$data->getHtml());
    }

    public function testGetPurifiedReportsTheSetting(): void
    {
        self::assertTrue((new MarkdownData('hi', 'gfm', true))->getPurified());
        self::assertFalse((new MarkdownData('hi', 'gfm', false))->getPurified());
    }
}
