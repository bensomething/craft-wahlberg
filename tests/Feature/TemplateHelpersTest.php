<?php

declare(strict_types=1);

namespace bensomething\wahlberg\tests\Feature;

use bensomething\wahlberg\Markdown\WahlbergFlavor;
use bensomething\wahlberg\MarkdownText;
use bensomething\wahlberg\tests\TestCase;
use CraftCms\Cms\Database\Factories\EntryFactory;
use CraftCms\Cms\Field\Data\MarkdownData;
use CraftCms\Cms\Markdown\Markdown;

use function CraftCms\Cms\renderString;

/** The Twig filters, and what's behind them. */
final class TemplateHelpersTest extends TestCase
{
    private const string ARTICLE = <<<'MD'
        # Title

        Intro with **bold** and a [link](https://craftcms.com).

        ## Getting started

        > [!NOTE]
        > Mind the gap.[^1]

        ![A photo](photo.jpg)

        ### Details

        - One
        - Two

        ```php
        echo 'code';
        ```

        ## Getting started

        [^1]: A footnote.
        MD;

    public function test_toc_lists_headings_with_the_flavours_ids(): void
    {
        $this->assertSame([
            ['level' => 1, 'text' => 'Title', 'id' => 'title'],
            ['level' => 2, 'text' => 'Getting started', 'id' => 'getting-started'],
            ['level' => 3, 'text' => 'Details', 'id' => 'details'],
            ['level' => 2, 'text' => 'Getting started', 'id' => 'getting-started-1'],
        ], $this->text()->toc(self::ARTICLE));
    }

    public function test_toc_ids_match_the_rendered_headings(): void
    {
        $html = app(Markdown::class)->parse(self::ARTICLE, WahlbergFlavor::NAME);

        foreach ($this->text()->toc(self::ARTICLE) as $heading) {
            $this->assertStringContainsString('id="'.$heading['id'].'"', $html);
        }
    }

    public function test_toc_can_be_limited_to_levels(): void
    {
        $this->assertSame(['Getting started', 'Details', 'Getting started'], array_column($this->text()->toc(self::ARTICLE, 2, 3), 'text'));
    }

    public function test_plain_text(): void
    {
        $this->assertSame(
            "Title\n\nIntro with bold and a link.\n\nGetting started\n\nMind the gap.\n\nDetails\n\nOne\n\nTwo\n\necho 'code';\n\nGetting started",
            $this->text()->plainText(self::ARTICLE),
        );
    }

    public function test_excerpt_cuts_at_a_word(): void
    {
        $text = $this->text();

        $this->assertSame('Title Intro with bold…', $text->excerpt(self::ARTICLE, 24));
        $this->assertSame('Short.', $text->excerpt('Short.', 24));
        $this->assertSame('Title Intro with bold and a link. Getting started Mind the gap. Details One Two', $text->excerpt('Title Intro with bold and a link. Getting started Mind the gap. Details One Two', 200));
        $this->assertSame('Supercalifragilistic…', $text->excerpt('Supercalifragilisticexpialidocious', 21));
    }

    public function test_reading_time(): void
    {
        $text = $this->text();

        $this->assertSame(0, $text->readingTime(''));
        $this->assertSame(1, $text->readingTime('A few words.'));
        $this->assertSame(3, $text->readingTime(str_repeat('word ', 401)));
        $this->assertSame(2, $text->readingTime(str_repeat('word ', 401), 300));
    }

    public function test_field_values_use_their_own_flavour(): void
    {
        $markdown = "Text.[^1]\n\n[^1]: Note.";

        // Craft's GFM has no footnotes: it reads the note as a link definition, so
        // the marker is a link with `^1` for its text. The Wahlberg flavour drops it
        $this->assertSame('Text.^1', $this->text()->plainText(new MarkdownData($markdown, 'gfm')));
        $this->assertSame('Text.', $this->text()->plainText(new MarkdownData($markdown, WahlbergFlavor::NAME)));
    }

    public function test_parse_refs_resolves_tags_outside_code_only(): void
    {
        $entry = EntryFactory::new()->title('Linked')->create();
        $tag = '{entry:'.$entry->id.':url}';

        $markdown = "[Linked]($tag)\n\nInline `$tag` and:\n\n```\n$tag\n```";
        $resolved = $this->text()->parseRefs($markdown);

        $this->assertStringNotContainsString("]($tag)", $resolved);
        $this->assertMatchesRegularExpression('#\[Linked\]\(https?://[^)]+\)#', $resolved);
        $this->assertStringContainsString("`$tag`", $resolved);
        $this->assertStringContainsString("```\n$tag\n```", $resolved);
    }

    public function test_parse_refs_leaves_markdown_without_tags_alone(): void
    {
        $this->assertSame('# Plain *Markdown*', $this->text()->parseRefs('# Plain *Markdown*'));
    }

    public function test_the_twig_filters(): void
    {
        $value = new MarkdownData("# Hello\n\nA *short* note.", WahlbergFlavor::NAME);

        $this->assertSame(
            'hello:Hello|Hello A short note.|Hello A…|1|Hello'."\n\n".'A short note.',
            renderString(
                '{% for h in body|toc %}{{ h.id }}:{{ h.text }}{% endfor %}|{{ body|excerpt(100) }}|{{ body|excerpt(8) }}|{{ body|readingTime }}|{{ body|plainText }}',
                ['body' => $value],
            ),
        );

        $this->assertSame('# Plain', renderString('{{ "# Plain"|parseMarkdownRefs }}'));
    }

    private function text(): MarkdownText
    {
        return app(MarkdownText::class);
    }
}
