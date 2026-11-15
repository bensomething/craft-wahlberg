<?php

declare(strict_types=1);

namespace bensomething\wahlberg\tests\Feature;

use bensomething\wahlberg\Markdown\WahlbergFlavor;
use bensomething\wahlberg\Plugin;
use bensomething\wahlberg\tests\TestCase;
use bensomething\wahlberg\WritingChecks;
use CraftCms\Cms\Database\Factories\EntryFactory;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Field\Data\MarkdownData;
use CraftCms\Cms\Field\Markdown;
use CraftCms\Cms\FieldLayout\FieldLayout;
use CraftCms\Cms\FieldLayout\FieldLayoutCompiler;
use CraftCms\Cms\FieldLayout\LayoutElements\CustomField;
use CraftCms\Cms\Plugin\Plugins;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\Fields;
use CraftCms\Cms\Ui\Contracts\Node;
use CraftCms\Cms\Ui\Controls\Markdown as MarkdownControl;
use CraftCms\Cms\Ui\Nodes\Field;

/** The writing checks, and the warning they put under a Markdown field. */
final class WritingChecksTest extends TestCase
{
    public function test_images_without_alt_text(): void
    {
        $this->assertSame(
            ['2 images have no alt text.'],
            $this->check("![](a.jpg)\n\n![ ](b.jpg)\n\n![A photo](c.jpg)", WritingChecks::ALT_TEXT),
        );
        $this->assertSame(['An image has no alt text.'], $this->check('![](a.jpg)', WritingChecks::ALT_TEXT));
    }

    public function test_level_1_headings(): void
    {
        $this->assertSame(
            ['“Title” is a level 1 heading. The page’s title is usually the only one, so start at level 2.'],
            $this->check("# Title\n\n## Fine", WritingChecks::H1),
        );
    }

    public function test_skipped_heading_levels(): void
    {
        $this->assertSame([
            '“Deep” is a level 3 heading, but it’s the first one. Start at level 2.',
            '“Deeper” is a level 5 heading, but the one before it is level 3.',
        ], $this->check("### Deep\n\n##### Deeper\n\n## Back up\n\n### Down one", WritingChecks::HEADING_LEVELS));
    }

    public function test_markdown_in_code_isnt_checked(): void
    {
        $this->assertSame([], $this->check("```md\n# Title\n\n![](a.jpg)\n```\n\n`![](b.jpg)`"));
    }

    public function test_links_to_entries_that_arent_live(): void
    {
        $live = EntryFactory::new()->title('Live one')->create();
        $disabled = Entry::find()->id(EntryFactory::new()->title('Off')->create()->id)->status(null)->one();
        $this->assertInstanceOf(Entry::class, $disabled);
        $disabled->enabled = false;
        $this->assertTrue(Elements::saveElement($disabled));
        $pending = EntryFactory::new()->title('Soon')->pending()->create();
        $expired = Entry::find()->id(EntryFactory::new()->title('Gone')->expired()->create()->id)->status(null)->one();
        $this->assertInstanceOf(Entry::class, $expired);

        $markdown = implode("\n\n", [
            "[Fine]({entry:{$live->id}:url})",
            "[Switched off]({entry:{$disabled->id}:url})",
            "[Coming]({entry:{$pending->id}@{$pending->siteId}:url})",
            "[Old]({entry:{$expired->uid}:url})",
            '[Nowhere]({entry:999999:url})',
            '[Elsewhere](https://craftcms.com)',
        ]);

        $this->assertSame([
            '“Switched off” links to “Off”, which is disabled.',
            '“Coming” links to “Soon”, which isn’t published yet.',
            '“Old” links to “Gone”, which has expired.',
            '“Nowhere” links to an entry that doesn’t exist.',
        ], $this->check($markdown, WritingChecks::ENTRY_LINKS));
    }

    public function test_author_text_is_escaped(): void
    {
        $this->assertSame(
            ['“1 &lt; 2 \\*deal\\*” is a level 1 heading. The page’s title is usually the only one, so start at level 2.'],
            $this->check('# 1 < 2 \*deal\*', WritingChecks::H1),
        );
    }

    public function test_nothing_runs_until_a_check_is_on(): void
    {
        Plugin::getInstance()->setSettings(['checks' => []]);

        $this->assertSame([], app(WritingChecks::class)->check('# Title'));
    }

    public function test_unknown_checks_from_the_config_file_are_ignored(): void
    {
        Plugin::getInstance()->setSettings(['checks' => ['h1', 'spelling']]);

        $this->assertSame(['h1'], app(WritingChecks::class)->enabled());
    }

    public function test_the_checks_setting_saves_and_rejects_unknown_ones(): void
    {
        $this->assertTrue(app(Plugins::class)->savePluginSettings(Plugin::getInstance(), ['checks' => ['altText', 'entryLinks']]));
        $this->assertSame(['altText', 'entryLinks'], Plugin::getInstance()->getSettings()->checks);
    }

    public function test_an_unknown_check_is_rejected(): void
    {
        $this->assertFalse(app(Plugins::class)->savePluginSettings(Plugin::getInstance(), ['checks' => ['spelling']]));
    }

    public function test_the_field_shows_problems_as_its_warning(): void
    {
        Plugin::getInstance()->setSettings(['checks' => ['h1', 'altText']]);

        $this->assertSame(
            "An image has no alt text.<br>\n“Title” is a level 1 heading. The page’s title is usually the only one, so start at level 2.",
            $this->warningFor("# Title\n\n![](a.jpg)"),
        );
    }

    public function test_the_fields_own_warning_is_kept(): void
    {
        Plugin::getInstance()->setSettings(['checks' => ['h1']]);

        $this->assertSame(
            "Mind the style guide.<br>\n“Title” is a level 1 heading. The page’s title is usually the only one, so start at level 2.",
            $this->warningFor('# Title', 'Mind the style guide.'),
        );
        $this->assertSame('Mind the style guide.', $this->warningFor('## Fine', 'Mind the style guide.'));
    }

    public function test_no_warning_without_problems_or_checks(): void
    {
        Plugin::getInstance()->setSettings(['checks' => ['h1']]);
        $this->assertNull($this->warningFor('## Fine'));

        Plugin::getInstance()->setSettings(['checks' => []]);
        $this->assertNull($this->warningFor('# Title'));
    }

    /** @return list<string> */
    private function check(string $markdown, string ...$checks): array
    {
        return app(WritingChecks::class)->check(
            new MarkdownData($markdown, WahlbergFlavor::NAME),
            checks: $checks === [] ? WritingChecks::ALL : array_values($checks),
        );
    }

    private function warningFor(string $markdown, ?string $layoutWarning = null): ?string
    {
        $field = Fields::createField(['type' => Markdown::class, 'name' => 'Body', 'handle' => 'body']);
        $this->assertTrue(Fields::saveField($field));

        $layout = FieldLayout::createFromConfig([
            'type' => Entry::class,
            'tabs' => [[
                'name' => 'Content',
                'elements' => [array_filter([
                    'type' => CustomField::class,
                    'fieldUid' => $field->uid,
                    'warning' => $layoutWarning,
                ])],
            ]],
        ]);

        // An entry whose value for the field is what's given, without a section
        $entry = new class($markdown) extends Entry
        {
            public function __construct(private readonly string $markdown)
            {
                parent::__construct();
            }

            public function getFieldValue(string $fieldHandle): mixed
            {
                return new MarkdownData($this->markdown, WahlbergFlavor::NAME);
            }
        };

        $node = $this->findField(app(FieldLayoutCompiler::class)->ui($layout, $entry)->nodes());
        $this->assertNotNull($node);

        return $node->props()['warning'] ?? null;
    }

    /** @param  array<Node>  $nodes */
    private function findField(array $nodes): ?Field
    {
        foreach ($nodes as $node) {
            if ($node instanceof Field && $node->getControl() instanceof MarkdownControl) {
                return $node;
            }

            if ($found = $this->findField($node->children())) {
                return $found;
            }
        }

        return null;
    }
}
