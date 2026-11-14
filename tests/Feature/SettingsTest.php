<?php

declare(strict_types=1);

namespace bensomething\wahlberg\tests\Feature;

use bensomething\wahlberg\Plugin;
use bensomething\wahlberg\Settings;
use bensomething\wahlberg\tests\TestCase;
use CraftCms\Cms\Plugin\Plugins;
use CraftCms\Cms\Ui\Contracts\Node;
use CraftCms\Cms\Ui\Controls\Table;
use CraftCms\Cms\Ui\Controls\Text;
use CraftCms\Cms\Ui\Enums\ControlMode;
use CraftCms\Cms\Ui\Nodes\Field;
use Illuminate\Support\Facades\Config;

/** The settings screen, what it accepts, and the config file overriding it. */
final class SettingsTest extends TestCase
{
    public function test_valid_snippets_save(): void
    {
        $this->assertTrue($this->save([
            'trigger' => ';',
            'snippets' => [
                ['label' => 'Callout', 'handle' => 'callout', 'body' => '> $1', 'fields' => 'body'],
                ['label' => 'Aside', 'handle' => '', 'body' => 'a', 'fields' => ''],
            ],
        ]));
    }

    public function test_a_snippet_needs_markdown(): void
    {
        $this->assertFalse($this->save(['snippets' => [['label' => 'Empty', 'handle' => 'empty', 'body' => '']]]));
        $this->assertTrue($this->settings()->errors()->has('snippets.0.body'));
    }

    public function test_handles_must_be_valid_and_unique(): void
    {
        $this->assertFalse($this->save(['snippets' => [['label' => 'A', 'handle' => '1nope', 'body' => 'a']]]));

        $this->assertFalse($this->save(['snippets' => [
            ['label' => 'A', 'handle' => 'same', 'body' => 'a'],
            ['label' => 'B', 'handle' => 'same', 'body' => 'b'],
        ]]));
    }

    public function test_the_trigger_is_one_visible_character(): void
    {
        $this->assertFalse($this->save(['trigger' => '//']));
        $this->assertFalse($this->save(['trigger' => ' ']));
        $this->assertFalse($this->save(['trigger' => '']));
    }

    public function test_the_screen_is_editable_by_default(): void
    {
        foreach (['snippets' => Table::class, 'trigger' => Text::class] as $setting => $class) {
            $field = $this->fieldFor($setting);
            $this->assertInstanceOf($class, $field->getControl());
            $this->assertSame(ControlMode::Editable, $field->getControl()->getMode());
        }
    }

    public function test_a_setting_the_config_file_sets_is_read_only(): void
    {
        Config::set('craft.wahlberg', ['snippets' => ['callout' => '> $1']]);

        $this->assertSame(ControlMode::ReadOnly, $this->fieldFor('snippets')->getControl()?->getMode());
        $this->assertSame(ControlMode::Editable, $this->fieldFor('trigger')->getControl()?->getMode());
    }

    /** @param  array<string, mixed>  $settings */
    private function save(array $settings): bool
    {
        return app(Plugins::class)->savePluginSettings(Plugin::getInstance(), $settings);
    }

    private function settings(): Settings
    {
        return Plugin::getInstance()->getSettings();
    }

    private function fieldFor(string $setting): Field
    {
        $ui = Plugin::getInstance()->settingsUi();
        $this->assertNotNull($ui);

        $field = $this->find($ui->nodes(), $setting);
        $this->assertNotNull($field, "No field for [$setting]");

        return $field;
    }

    /** @param  array<Node>  $nodes */
    private function find(array $nodes, string $setting): ?Field
    {
        foreach ($nodes as $node) {
            if ($node instanceof Field && (array) $node->getControl()?->path() === [$setting]) {
                return $node;
            }

            if ($found = $this->find($node->children(), $setting)) {
                return $found;
            }
        }

        return null;
    }
}
