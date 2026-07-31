<?php

namespace bensomething\wahlberg\tests\unit;

use bensomething\wahlberg\Editor;
use bensomething\wahlberg\tests\TestCase;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * The markup the editor builds itself, as opposed to what it hands to a template.
 * `inputHtml()` needs a view and can't be reached here; these can.
 */
class EditorTest extends TestCase
{
    /**
     * @param array<string, string|null> $snippets Label and icon per handle
     * @return array<string, array{label: string, body: string, icon: string|null}>
     */
    private function snippets(array $snippets = ['callout' => null]): array
    {
        $out = [];

        foreach ($snippets as $handle => $icon) {
            $out[$handle] = ['label' => ucfirst($handle), 'body' => 'body', 'icon' => $icon];
        }

        return $out;
    }

    #[TestDox('the snippets menu carries one item per snippet, keyed by handle')]
    public function testSnippetsMenu(): void
    {
        $html = Editor::snippetsMenuHtml($this->snippets([
            'callout' => 'circle-info',
            'figure' => null,
        ]));

        self::assertStringContainsString('data-snippet="callout"', $html);
        self::assertStringContainsString('data-snippet="figure"', $html);
        self::assertStringContainsString('Callout', $html);

        // Craft's own menu classes, which is where the styling comes from
        self::assertStringContainsString('menu--disclosure', $html);
        self::assertStringContainsString('class="menu-item"', $html);
    }

    #[TestDox('nothing defined means no menu at all, rather than an empty one')]
    public function testSnippetsMenuEmpty(): void
    {
        self::assertSame('', Editor::snippetsMenuHtml([]));
    }

    #[TestDox('a snippet without an icon still gets one, so the labels line up')]
    public function testSnippetsMenuIconFallback(): void
    {
        $named = Editor::snippetsMenuHtml($this->snippets(['x' => 'circle-info']));
        $unnamed = Editor::snippetsMenuHtml($this->snippets(['x' => null]));

        // An icon either way, and a different one, so the fallback is standing in
        // rather than the named one being ignored
        self::assertStringContainsString('<svg', $named);
        self::assertStringContainsString('<svg', $unnamed);
        self::assertNotSame($named, $unnamed);
    }

    #[TestDox('a label is encoded, since it comes from a config file')]
    public function testSnippetsMenuEncodesLabels(): void
    {
        $html = Editor::snippetsMenuHtml([
            'x' => ['label' => 'Fish & <chips>', 'body' => 'body', 'icon' => null],
        ]);

        self::assertStringContainsString('Fish &amp; &lt;chips&gt;', $html);
        self::assertStringNotContainsString('<chips>', $html);
    }

    #[TestDox('the snippets button follows the toolbar setting, the menu doesn’t')]
    public function testSnippetsButtonFollowsTheToolbar(): void
    {
        $snippets = $this->snippets();

        self::assertStringContainsString(
            'data-snippets-trigger',
            Editor::snippetsButtonHtml($snippets, ['snippets']),
        );

        self::assertSame('', Editor::snippetsButtonHtml($snippets, ['bold']));
        self::assertSame('', Editor::snippetsButtonHtml([], ['snippets']));

        // The shortcut opens it either way, so the menu is rendered regardless
        self::assertNotSame('', Editor::snippetsMenuHtml($snippets));
    }

    #[TestDox('the guide button follows the toolbar setting')]
    public function testGuideFollowsTheToolbar(): void
    {
        self::assertStringContainsString('data-guide-trigger', Editor::guideHtml(['guide']));
        self::assertSame('', Editor::guideHtml(['bold']));

        // Null is "everything", which is what the standalone editor passes
        self::assertStringContainsString('data-guide-trigger', Editor::guideHtml());
    }

    #[TestDox('every guide row is a copyable example with something to say about it')]
    public function testGuide(): void
    {
        $rows = Editor::guide();

        self::assertNotSame([], $rows);

        foreach ($rows as $row) {
            self::assertNotSame('', trim($row['syntax']));
            self::assertNotSame('', trim($row['label']));
        }

        $html = Editor::guideHtml(['guide']);

        // Craft's copy-to-clipboard element, one per row
        self::assertSame(count($rows), substr_count($html, '<craft-copy-attribute'));

        // The syntax is the row's header, so the table reads without a header row
        self::assertSame(count($rows), substr_count($html, '<th scope="row">'));
    }

    #[TestDox('guide syntax is encoded, so an example is shown rather than rendered')]
    public function testGuideEncodesSyntax(): void
    {
        $html = Editor::guideHtml(['guide']);

        // `> quote` is the row that would otherwise open a tag
        self::assertStringContainsString('&gt; quote', $html);
        self::assertStringNotContainsString('<craft-copy-attribute value="> quote"', $html);
    }

    #[TestDox('a shortcut is the key to hold the modifier with, and says so if Shift is held too')]
    public function testToolbarShortcuts(): void
    {
        $shortcuts = [];

        foreach (Editor::toolbar() as $group) {
            foreach ($group as $button) {
                if (isset($button['shortcut'])) {
                    $shortcuts[$button['command']] = $button['shortcut'];
                }
            }
        }

        self::assertSame([
            'bold' => 'B',
            'italic' => 'I',
            'link' => 'K',
            'entry' => 'shift+E',
            'asset' => 'shift+U',
        ], $shortcuts);

        // The editor reads this attribute to label the button ⌘K or ⌘⇧E, and to
        // decide which modifiers the keystroke wants. A shortcut spelled any other
        // way would come out as a tooltip that lies
        foreach ($shortcuts as $command => $shortcut) {
            self::assertMatchesRegularExpression(
                '/^(shift\+)?[A-Z]$/',
                $shortcut,
                "`$command` has a shortcut the editor can't read",
            );
        }
    }

    #[TestDox('every command has a label, and the defaults are all real commands')]
    public function testCommands(): void
    {
        $commands = Editor::commands();

        self::assertNotSame([], $commands);

        foreach ($commands as $command => $label) {
            self::assertNotSame('', trim($label), "`$command` has no label");
        }

        // Six levels, so the heading control has something to offer
        self::assertCount(6, Editor::HEADING_LEVELS);

        foreach (Editor::HEADING_LEVELS as $level) {
            self::assertArrayHasKey("h$level", $commands);
        }
    }
}
