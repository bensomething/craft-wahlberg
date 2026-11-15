<?php

declare(strict_types=1);

namespace bensomething\wahlberg\tests\Feature;

use bensomething\wahlberg\Events\SnippetsRegistering;
use bensomething\wahlberg\Plugin;
use bensomething\wahlberg\Snippets;
use bensomething\wahlberg\tests\TestCase;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Field\Contracts\FieldInterface;
use CraftCms\Cms\Field\Markdown;
use CraftCms\Cms\Field\PlainText;
use CraftCms\Cms\FieldLayout\FieldLayout;
use CraftCms\Cms\FieldLayout\FieldLayoutCompiler;
use CraftCms\Cms\FieldLayout\LayoutElements\CustomField;
use CraftCms\Cms\Support\Facades\Fields;
use CraftCms\Cms\Ui\Contracts\Control;
use CraftCms\Cms\Ui\Contracts\Node;
use CraftCms\Cms\Ui\Controls\Markdown as MarkdownControl;
use Illuminate\Support\Facades\Event;

/** The `/` and `@` menus on native Markdown fields, through a real field layout compile. */
final class AttachMenusTest extends TestCase
{
    public function test_a_markdown_field_gets_the_snippets(): void
    {
        $this->configure([
            'callout' => ['label' => 'Callout', 'body' => '> ${1:Text}$0'],
        ]);

        $triggers = $this->triggersFor($this->field('body'));

        $this->assertSame([[
            'trigger' => '/',
            'boundary' => 'whitespace',
            'label' => 'Snippets',
            'options' => [[
                'label' => 'Callout',
                'value' => '> Text',
                'keywords' => ['callout'],
                'data' => ['wahlberg' => ['stops' => [[2, 6], [6, 6]], 'inline' => false]],
            ]],
        ]], $triggers);
    }

    public function test_the_trigger_is_configurable(): void
    {
        $this->configure(['note' => 'Note'], ['trigger' => ';']);

        $this->assertSame(';', $this->triggersFor($this->field('body'))[0]['trigger']);
    }

    public function test_a_trigger_outside_the_list_falls_back_to_slash(): void
    {
        // As the config file could set it, which isn't validated
        $this->configure(['note' => 'Note'], ['trigger' => '#']);

        $this->assertSame('/', $this->triggersFor($this->field('body'))[0]['trigger']);
    }

    public function test_a_label_defaults_to_the_handle(): void
    {
        $this->configure(['figureCaption' => 'x']);

        $this->assertSame('Figure Caption', $this->triggersFor($this->field('body'))[0]['options'][0]['label']);
    }

    public function test_fields_limits_which_fields_offer_a_snippet(): void
    {
        $this->configure([
            'everywhere' => 'a',
            'bodyOnly' => ['body' => 'b', 'fields' => ['body']],
        ]);

        $this->assertSame(['everywhere', 'bodyOnly'], $this->handlesFor($this->field('body')));
        $this->assertSame(['everywhere'], $this->handlesFor($this->field('summary')));
    }

    public function test_fields_matches_a_field_renamed_by_its_layout(): void
    {
        $this->configure(['bodyOnly' => ['body' => 'b', 'fields' => ['body']]]);

        $this->assertSame(['bodyOnly'], $this->handlesFor($this->field('body'), layoutHandle: 'copy'));
    }

    public function test_no_snippets_leaves_the_field_alone(): void
    {
        $this->configure([]);

        $this->assertNull($this->triggersFor($this->field('body')));
    }

    public function test_a_field_with_no_matching_snippets_is_left_alone(): void
    {
        $this->configure(['bodyOnly' => ['body' => 'b', 'fields' => ['body']]]);

        $this->assertNull($this->triggersFor($this->field('summary')));
    }

    public function test_other_field_types_are_left_alone(): void
    {
        $this->configure(['note' => 'Note']);

        $field = Fields::createField(['type' => PlainText::class, 'name' => 'Title', 'handle' => 'heading']);
        $this->assertTrue(Fields::saveField($field));

        $control = $this->controlFor($field);
        $this->assertNotInstanceOf(MarkdownControl::class, $control);
    }

    public function test_plugins_can_register_snippets_and_config_wins_a_clash(): void
    {
        Event::listen(SnippetsRegistering::class, function (SnippetsRegistering $event): void {
            $event->snippets['fromPlugin'] = 'p';
            $event->snippets['clash'] = 'plugin';
        });

        $this->configure(['clash' => 'config']);

        $options = $this->triggersFor($this->field('body'))[0]['options'];

        $this->assertSame(['fromPlugin', 'clash'], array_merge(...array_column($options, 'keywords')));
        $this->assertSame('config', $options[1]['value']);
    }

    public function test_rows_from_the_settings_screen_work_too(): void
    {
        $this->configure([
            ['label' => 'Pull quote', 'handle' => '', 'body' => '> $1', 'fields' => ''],
            ['label' => 'Aside', 'handle' => 'aside', 'body' => 'a', 'fields' => 'body, intro'],
            ['label' => 'Empty', 'handle' => 'empty', 'body' => '', 'fields' => ''],
        ]);

        // A blank handle comes from the label, a blank Fields means all of them,
        // and a row with no Markdown is skipped
        $this->assertSame(['pullQuote', 'aside'], $this->handlesFor($this->field('body')));
        $this->assertSame(['pullQuote'], $this->handlesFor($this->field('summary')));
    }

    public function test_entry_links_add_an_at_menu(): void
    {
        $this->configure(['note' => 'Note']);

        $field = $this->field('body', linkTypes: ['entry', 'url']);
        $triggers = $this->triggersFor($field);

        $this->assertCount(2, $triggers);
        $this->assertSame('@', $triggers[1]['trigger']);
        $this->assertSame('whitespace', $triggers[1]['boundary']);
        $this->assertSame('Entries', $triggers[1]['label']);
        $this->assertArrayNotHasKey('options', $triggers[1]);
        $this->assertStringContainsString('/wahlberg/entries?', $triggers[1]['source']);
        $this->assertStringContainsString('field='.$field->id, $triggers[1]['source']);
    }

    public function test_no_at_menu_when_the_field_doesnt_allow_entry_links(): void
    {
        $this->configure(['note' => 'Note']);

        $this->assertSame(['/'], array_column($this->triggersFor($this->field('body', linkTypes: ['url'])) ?? [], 'trigger'));
    }

    public function test_entry_links_work_without_snippets(): void
    {
        $this->configure([]);

        $this->assertSame(['@'], array_column($this->triggersFor($this->field('body', linkTypes: ['entry'])) ?? [], 'trigger'));
    }

    public function test_a_snippet_can_be_inline(): void
    {
        $this->configure([
            'callout' => ['body' => '> $1'],
            'kbd' => ['body' => '<kbd>$1</kbd>', 'inline' => true],
            ['label' => 'Table', 'handle' => 'table', 'body' => '| $1 |', 'inline' => false, 'fields' => ''],
            ['label' => 'Abbr', 'handle' => 'abbr', 'body' => '<abbr>$1</abbr>', 'inline' => true, 'fields' => ''],
        ]);

        $options = $this->triggersFor($this->field('body'))[0]['options'];
        $inline = array_combine(
            array_merge(...array_column($options, 'keywords')),
            array_map(fn (array $option) => $option['data']['wahlberg']['inline'], $options),
        );

        $this->assertSame(['callout' => false, 'kbd' => true, 'table' => false, 'abbr' => true], $inline);

        // Tagged in the menu
        $this->assertSame([null, 'Inline', null, 'Inline'], array_map(fn (array $option) => $option['data']['hint'] ?? null, $options));
    }

    /**
     * Through the settings model, which is where the config file lands too.
     *
     * @param  array<array-key, mixed>  $snippets
     * @param  array<string, mixed>  $extra
     */
    private function configure(array $snippets, array $extra = []): void
    {
        Plugin::getInstance()->setSettings($extra + ['snippets' => $snippets]);
        app(Snippets::class)->reset();
    }

    /**
     * Without entry links unless a test allows them, so the snippets menu is alone.
     *
     * @param  list<string>  $linkTypes
     */
    private function field(string $handle, array $linkTypes = ['url']): FieldInterface
    {
        $field = Fields::createField([
            'type' => Markdown::class,
            'name' => ucfirst($handle),
            'handle' => $handle,
            'linkSettingsTypes' => $linkTypes,
        ]);
        $this->assertTrue(Fields::saveField($field));

        return $field;
    }

    private function controlFor(FieldInterface $field, ?string $layoutHandle = null): ?Control
    {
        $layout = FieldLayout::createFromConfig([
            'type' => Entry::class,
            'tabs' => [[
                'name' => 'Content',
                'elements' => [array_filter([
                    'type' => CustomField::class,
                    'fieldUid' => $field->uid,
                    'handle' => $layoutHandle,
                ])],
            ]],
        ]);

        $ui = app(FieldLayoutCompiler::class)->ui($layout);

        return $this->findControl($ui->nodes());
    }

    /** @param  array<Node>  $nodes */
    private function findControl(array $nodes): ?Control
    {
        foreach ($nodes as $node) {
            if ($control = $node->getControl()) {
                return $control;
            }

            if ($control = $this->findControl($node->children())) {
                return $control;
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>>|null */
    private function triggersFor(FieldInterface $field, ?string $layoutHandle = null): ?array
    {
        $control = $this->controlFor($field, $layoutHandle);
        $this->assertInstanceOf(MarkdownControl::class, $control);

        // Left out of the props altogether when there are none
        return $control->props()['textExpanderTriggers'] ?? null;
    }

    /** @return list<string> */
    private function handlesFor(FieldInterface $field, ?string $layoutHandle = null): array
    {
        $options = $this->triggersFor($field, $layoutHandle)[0]['options'] ?? [];

        return array_merge(...array_column($options, 'keywords'));
    }
}
