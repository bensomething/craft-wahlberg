<?php

declare(strict_types=1);

namespace bensomething\wahlberg\tests\Feature;

use bensomething\wahlberg\Markdown\WahlbergFlavor;
use bensomething\wahlberg\tests\TestCase;
use CraftCms\Cms\Field\Markdown as MarkdownField;
use CraftCms\Cms\Markdown\Markdown;
use CraftCms\Cms\Plugin\Plugins;
use CraftCms\Cms\Support\Facades\Fields;
use PHPUnit\Framework\Attributes\DataProvider;

/** The Wahlberg Markdown flavour, and the field menu it goes on. */
final class FlavourTest extends TestCase
{
    public function test_it_is_on_the_fields_flavour_menu(): void
    {
        $this->assertContains(WahlbergFlavor::NAME, array_column(MarkdownField::flavorOptions(), 'value'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function alerts(): iterable
    {
        yield 'note' => [
            "> [!NOTE]\n> Mind the gap.",
            "<div class=\"markdown-alert markdown-alert-note\">\n<p class=\"markdown-alert-title\">Note</p>\n<p>Mind the gap.</p>\n</div>\n",
        ];

        yield 'any case, more than one paragraph' => [
            "> [!warning]\n>\n> One.\n>\n> Two.",
            "<div class=\"markdown-alert markdown-alert-warning\">\n<p class=\"markdown-alert-title\">Warning</p>\n<p>One.</p>\n<p>Two.</p>\n</div>\n",
        ];

        yield 'marker only' => [
            '> [!TIP]',
            "<div class=\"markdown-alert markdown-alert-tip\">\n<p class=\"markdown-alert-title\">Tip</p>\n</div>\n",
        ];

        yield 'inline formatting kept' => [
            "> [!CAUTION]\n> Don’t **ever**.",
            "<div class=\"markdown-alert markdown-alert-caution\">\n<p class=\"markdown-alert-title\">Caution</p>\n<p>Don’t <strong>ever</strong>.</p>\n</div>\n",
        ];
    }

    #[DataProvider('alerts')]
    public function test_alerts(string $markdown, string $html): void
    {
        $this->assertSame($html, $this->parse($markdown));
    }

    /** @return iterable<string, array{string}> */
    public static function quotes(): iterable
    {
        yield 'plain quote' => ['> Just a quote.'];
        yield 'text after the marker' => ['> [!NOTE] Not on its own line.'];
        yield 'unknown type' => ["> [!DANGER]\n> Not one of GitHub’s."];
        yield 'marker not first' => ["> First.\n> [!NOTE]"];
    }

    #[DataProvider('quotes')]
    public function test_other_quotes_stay_quotes(string $markdown): void
    {
        $html = $this->parse($markdown);

        $this->assertStringStartsWith('<blockquote>', $html);
        $this->assertStringNotContainsString('markdown-alert', $html);
    }

    public function test_footnotes(): void
    {
        $html = $this->parse("Text.[^1]\n\n[^1]: The note.");

        $this->assertStringContainsString('href="#fn:1"', $html);
        $this->assertStringContainsString('The note.', $html);
    }

    public function test_highlights(): void
    {
        $this->assertSame("<p>A <mark>marked</mark> word.</p>\n", $this->parse('A ==marked== word.'));
        $this->assertSame('A <mark>marked</mark> word.', app(Markdown::class)->parseParagraph('A ==marked== word.', WahlbergFlavor::NAME));
    }

    public function test_headings_get_ids_and_no_anchor(): void
    {
        $this->assertSame(
            "<h2 id=\"getting-started\">Getting started</h2>\n<h2 id=\"getting-started-1\">Getting started</h2>\n",
            $this->parse("## Getting started\n\n## Getting started"),
        );
    }

    public function test_it_keeps_gfm(): void
    {
        $html = $this->parse("| a |\n| - |\n| b |\n\n- [x] done\n\n~~gone~~ https://craftcms.com");

        $this->assertStringContainsString('<table>', $html);
        $this->assertStringContainsString('type="checkbox"', $html);
        $this->assertStringContainsString('<del>gone</del>', $html);
        $this->assertStringContainsString('<a href="https://craftcms.com">', $html);
    }

    public function test_unsafe_links_are_dropped(): void
    {
        $this->assertStringNotContainsString('javascript:', $this->parse('[x](javascript:alert(1))'));
    }

    public function test_uninstalling_moves_fields_back_to_gfm(): void
    {
        $field = Fields::createField([
            'type' => MarkdownField::class,
            'name' => 'Body',
            'handle' => 'body',
            'flavor' => WahlbergFlavor::NAME,
        ]);
        $this->assertTrue(Fields::saveField($field));

        $this->assertTrue(app(Plugins::class)->uninstallPlugin('wahlberg'));

        $field = Fields::getFieldByHandle('body', false);
        $this->assertInstanceOf(MarkdownField::class, $field);
        $this->assertSame(Markdown::FLAVOR_GFM, $field->flavor);
    }

    private function parse(string $markdown): string
    {
        return app(Markdown::class)->parse($markdown, WahlbergFlavor::NAME);
    }
}
