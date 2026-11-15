<?php

declare(strict_types=1);

namespace bensomething\wahlberg\tests\Feature;

use bensomething\wahlberg\tests\TestCase;
use bensomething\wahlberg\Upgrade\LegacyFields;
use CraftCms\Cms\Field\Markdown as MarkdownField;
use CraftCms\Cms\Markdown\Markdown;
use CraftCms\Cms\ProjectConfig\ProjectConfig;
use CraftCms\Cms\Support\Facades\Fields;
use Illuminate\Support\Str;

/** Wahlberg 1.x's fields becoming Craft's own Markdown field. */
final class UpgradeTest extends TestCase
{
    public function test_defaults_map_to_the_native_field(): void
    {
        [$settings, $notes] = LegacyFields::settings([]);

        $this->assertSame(Markdown::FLAVOR_GFM_COMMENT, $settings['flavor']);
        $this->assertTrue($settings['sanitizeHtml']);
        $this->assertSame(2, $settings['initialRows']);
        $this->assertSame([
            MarkdownField::TOOLBAR_HEADING_2,
            MarkdownField::TOOLBAR_BOLD,
            MarkdownField::TOOLBAR_ITALIC,
            MarkdownField::TOOLBAR_STRIKETHROUGH,
            MarkdownField::TOOLBAR_QUOTE,
            MarkdownField::TOOLBAR_CODE,
            MarkdownField::TOOLBAR_UNORDERED_LIST,
            MarkdownField::TOOLBAR_ORDERED_LIST,
            MarkdownField::TOOLBAR_LINK,
            MarkdownField::TOOLBAR_ASSET,
            MarkdownField::TOOLBAR_PREVIEW,
        ], $settings['toolbarButtons']);
        $this->assertSame(['entry', 'url'], $settings['linkSettingsTypes']);
        $this->assertSame('*', $settings['availableVolumes']);
        $this->assertSame([], $notes);
    }

    public function test_settings_carry_across(): void
    {
        [$settings] = LegacyFields::settings([
            'flavour' => 'extra',
            'preserveLineBreaks' => true,
            'encodeHtml' => true,
            'inlineOnly' => true,
            'showToolbar' => false,
            'showStats' => true,
            'purifyHtml' => false,
            'placeholder' => 'Write…',
            'minRows' => 5,
            'charLimit' => '500',
            'byteLimit' => '',
            'toolbarButtons' => ['bold', 'link', 'snippets'],
            'showPreview' => false,
            'availableVolumes' => ['volume:abc', 'volume:def'],
            'showUnpermittedVolumes' => true,
        ]);

        $this->assertSame('extra', $settings['flavor']);
        $this->assertTrue($settings['encode']);
        $this->assertTrue($settings['inlineOnly']);
        $this->assertFalse($settings['showToolbar']);
        $this->assertTrue($settings['showStats']);
        $this->assertFalse($settings['sanitizeHtml']);
        $this->assertSame('Write…', $settings['placeholder']);
        $this->assertSame(5, $settings['initialRows']);
        $this->assertSame(500, $settings['charLimit']);
        $this->assertNull($settings['byteLimit']);
        $this->assertSame([MarkdownField::TOOLBAR_BOLD, MarkdownField::TOOLBAR_LINK], $settings['toolbarButtons']);
        $this->assertSame(['url'], $settings['linkSettingsTypes']);
        $this->assertSame(['abc', 'def'], $settings['availableVolumes']);
        $this->assertTrue($settings['showUnpermittedVolumes']);
    }

    public function test_gfm_without_line_breaks_stays_gfm(): void
    {
        $this->assertSame(Markdown::FLAVOR_GFM, LegacyFields::settings(['preserveLineBreaks' => false])[0]['flavor']);
    }

    public function test_what_cant_come_across_is_noted(): void
    {
        [, $notes] = LegacyFields::settings([
            'fontSize' => 16,
            'lineLength' => 'comfortable',
            'floatingToolbar' => false,
            'parseRefs' => false,
            'purifierConfig' => 'Strict.json',
            'availableVolumes' => ['volume:abc', 'folder:xyz'],
        ]);

        $this->assertCount(5, $notes);
        $this->assertStringContainsString('Font Size', $notes[3]);
        $this->assertStringContainsString('Line Length', $notes[4]);
        $this->assertStringContainsString('Strict.json', $notes[2]);
    }

    public function test_fields_convert_through_project_config(): void
    {
        $uid = $this->legacyField('body', ['flavour' => 'original', 'toolbarButtons' => ['bold', 'entry'], 'availableSnippets' => ['callout']]);
        $this->legacyField('summary', ['availableSnippets' => ['callout', 'aside']]);

        $fields = app(LegacyFields::class);
        $this->assertSame(['callout' => ['body', 'summary'], 'aside' => ['summary']], $fields->snippetFields());

        $plan = $fields->convert();
        $this->assertSame(['body', 'summary'], array_column($plan, 'handle'));

        Fields::refreshFields();
        $field = Fields::getFieldByUid($uid);

        $this->assertInstanceOf(MarkdownField::class, $field);
        $this->assertSame('body', $field->handle);
        $this->assertSame('original', $field->flavor);
        $this->assertSame(['entry'], $field->linkSettingsTypes);
        $this->assertSame([], $fields->plan());
    }

    public function test_the_migration_converts_fields(): void
    {
        $uid = $this->legacyField('body');

        // As project config has it on a site still on 1.x
        app(ProjectConfig::class)->set('plugins.wahlberg.schemaVersion', '1.0.0');
        $this->migration()->up();

        Fields::refreshFields();
        $this->assertInstanceOf(MarkdownField::class, Fields::getFieldByUid($uid));
    }

    public function test_the_migration_leaves_project_config_thats_been_through_it(): void
    {
        $uid = $this->legacyField('body');

        app(ProjectConfig::class)->set('plugins.wahlberg.schemaVersion', '2.0.0');
        $this->migration()->up();

        $this->assertSame(LegacyFields::TYPE, app(ProjectConfig::class)->get("fields.$uid.type"));
    }

    private function migration(): object
    {
        return require dirname(__DIR__, 2).'/database/migrations/2026_11_15_000000_convert_wahlberg_1_fields.php';
    }

    public function test_the_command_shows_a_dry_run_and_converts(): void
    {
        $uid = $this->legacyField('body', ['fontSize' => 18, 'availableSnippets' => ['callout']]);

        $this->artisan('wahlberg:convert-fields', ['--dry-run' => true])
            ->expectsOutputToContain('would become')
            ->expectsOutputToContain('Body (body)')
            ->expectsOutputToContain('Font Size')
            ->expectsOutputToContain("callout: ['body']")
            ->assertSuccessful();

        $this->assertSame(LegacyFields::TYPE, app(ProjectConfig::class)->get("fields.$uid.type"));

        $this->artisan('wahlberg:convert-fields')->expectsOutputToContain('are now')->assertSuccessful();
        $this->assertSame(MarkdownField::class, app(ProjectConfig::class)->get("fields.$uid.type"));

        $this->artisan('wahlberg:convert-fields')->expectsOutputToContain('no Wahlberg 1.x fields')->assertSuccessful();
    }

    /** @param  array<string, mixed>  $settings */
    private function legacyField(string $handle, array $settings = []): string
    {
        $uid = (string) Str::uuid();

        app(ProjectConfig::class)->set("fields.$uid", [
            'name' => ucfirst($handle),
            'handle' => $handle,
            'type' => LegacyFields::TYPE,
            'settings' => $settings,
            'searchable' => true,
            'translationMethod' => 'none',
            'translationKeyFormat' => null,
            'columnSuffix' => null,
            'instructions' => null,
        ]);

        return $uid;
    }
}
