<?php

declare(strict_types=1);

namespace bensomething\wahlberg\Upgrade;

use CraftCms\Cms\Field\Markdown as MarkdownField;
use CraftCms\Cms\Markdown\Markdown;
use CraftCms\Cms\ProjectConfig\ProjectConfig;

/**
 * Turns Wahlberg 1.x's Markdown fields into Craft's own, keeping each field's
 * settings where the native field has an equivalent.
 *
 * Only project config changes. Both fields keep the Markdown as typed, as a string
 * in the element's content, so entries read the same afterwards without being
 * touched. What can't come across is listed in the notes rather than dropped
 * quietly.
 *
 * @phpstan-type Conversion array{uid: string, name: string, handle: string, settings: array<string, mixed>, notes: list<string>}
 */
class LegacyFields
{
    /** 1.x's field type, as project config stores it. */
    public const string TYPE = 'bensomething\\wahlberg\\fields\\MarkdownField';

    /** 1.x's toolbar buttons, by what the native toolbar calls them. */
    private const array BUTTONS = [
        'h1' => MarkdownField::TOOLBAR_HEADING_1,
        'h2' => MarkdownField::TOOLBAR_HEADING_2,
        'h3' => MarkdownField::TOOLBAR_HEADING_3,
        'h4' => MarkdownField::TOOLBAR_HEADING_4,
        'h5' => MarkdownField::TOOLBAR_HEADING_5,
        'h6' => MarkdownField::TOOLBAR_HEADING_6,
        'bold' => MarkdownField::TOOLBAR_BOLD,
        'italic' => MarkdownField::TOOLBAR_ITALIC,
        'strike' => MarkdownField::TOOLBAR_STRIKETHROUGH,
        'quote' => MarkdownField::TOOLBAR_QUOTE,
        'code' => MarkdownField::TOOLBAR_CODE,
        'ul' => MarkdownField::TOOLBAR_UNORDERED_LIST,
        'ol' => MarkdownField::TOOLBAR_ORDERED_LIST,
        // The native Link button links to entries as well as URLs
        'link' => MarkdownField::TOOLBAR_LINK,
        'entry' => MarkdownField::TOOLBAR_LINK,
        'asset' => MarkdownField::TOOLBAR_ASSET,
        'guide' => MarkdownField::TOOLBAR_GUIDE,
    ];

    /** 1.x settings the native field has nothing like, with what to tell the developer. */
    private const array DROPPED = [
        'fontSize' => 'Font Size',
        'lineLength' => 'Line Length',
        'maxRows' => 'Max Rows',
        'paragraphOnEnter' => 'New Paragraph on Return',
        'floatingToolbar' => 'Floating Toolbar',
        'showHighlighting' => 'Syntax Highlighting',
        'showUnpermittedFiles' => 'Show Unpermitted Files',
    ];

    /** 1.x's defaults, so a setting left alone isn't reported as lost. */
    private const array DEFAULTS = [
        'fontSize' => 14,
        'lineLength' => 'full',
        'maxRows' => null,
        'paragraphOnEnter' => false,
        'floatingToolbar' => false,
        'showHighlighting' => true,
        'showUnpermittedFiles' => false,
    ];

    public function __construct(
        private readonly ProjectConfig $projectConfig,
    ) {}

    /**
     * What converting would do to each 1.x field, without doing it.
     *
     * @return list<Conversion>
     */
    public function plan(): array
    {
        $fields = $this->projectConfig->get(ProjectConfig::PATH_FIELDS) ?? [];
        $plan = [];

        foreach (is_array($fields) ? $fields : [] as $uid => $config) {
            if (! is_array($config) || ($config['type'] ?? null) !== self::TYPE) {
                continue;
            }

            $settings = is_array($config['settings'] ?? null) ? $config['settings'] : [];
            [$native, $notes] = self::settings($settings);

            $plan[] = [
                'uid' => (string) $uid,
                'name' => (string) ($config['name'] ?? ''),
                'handle' => (string) ($config['handle'] ?? ''),
                'settings' => $native,
                'notes' => $notes,
            ];
        }

        return $plan;
    }

    /**
     * Converts every 1.x field, through project config, so the change is deployed
     * like any other field change.
     *
     * @return list<Conversion>
     */
    public function convert(): array
    {
        $plan = $this->plan();

        foreach ($plan as $field) {
            $path = ProjectConfig::PATH_FIELDS.'.'.$field['uid'];
            $config = $this->projectConfig->get($path);

            if (! is_array($config)) {
                continue;
            }

            $config['type'] = MarkdownField::class;
            $config['settings'] = $field['settings'];

            $this->projectConfig->set($path, $config, "Convert the “{$field['handle']}” field from Wahlberg 1.x to Craft’s Markdown field");
        }

        return $plan;
    }

    /**
     * Snippets some 1.x field limited itself to, with the fields that offered each.
     * 2.x sets this on the snippet instead, with its `fields`.
     *
     * @return array<string, list<string>> Field handles, by snippet handle
     */
    public function snippetFields(): array
    {
        $fields = $this->projectConfig->get(ProjectConfig::PATH_FIELDS) ?? [];
        $limited = false;
        $offered = [];

        foreach (is_array($fields) ? $fields : [] as $config) {
            if (! is_array($config) || ($config['type'] ?? null) !== self::TYPE) {
                continue;
            }

            $snippets = $config['settings']['availableSnippets'] ?? '*';

            if (! is_array($snippets)) {
                continue;
            }

            $limited = true;

            foreach ($snippets as $snippet) {
                $offered[(string) $snippet][] = (string) ($config['handle'] ?? '');
            }
        }

        return $limited ? $offered : [];
    }

    /**
     * The plan as lines for a developer to read: each field, what didn't come
     * across, and the snippets to give `fields`.
     *
     * @param  list<Conversion>  $plan
     * @param  array<string, list<string>>  $snippetFields
     * @return list<string>
     */
    public static function report(array $plan, array $snippetFields): array
    {
        $lines = [];

        foreach ($plan as $field) {
            $lines[] = sprintf('%s (%s)', $field['name'], $field['handle']);

            foreach ($field['notes'] as $note) {
                $lines[] = '  - '.$note;
            }
        }

        if ($snippetFields !== []) {
            $lines[] = 'Snippets are limited to fields on the snippet now, not the field. To keep offering each where it was, give it these `fields` in config/craft/wahlberg.php or on the settings screen:';

            foreach ($snippetFields as $snippet => $handles) {
                $lines[] = sprintf("  - %s: ['%s']", $snippet, implode("', '", $handles));
            }
        }

        return $lines;
    }

    /**
     * A 1.x field's settings as the native field's, and notes on anything that
     * didn't come across.
     *
     * @param  array<string, mixed>  $legacy
     * @return array{array<string, mixed>, list<string>}
     */
    public static function settings(array $legacy): array
    {
        $notes = [];

        // 1.x resolved GFM with line breaks kept to Craft's `gfm-comment` flavour at
        // parse time. The native field takes the flavour as it's parsed
        $flavour = (string) ($legacy['flavour'] ?? Markdown::FLAVOR_GFM);
        $flavour = $flavour === Markdown::FLAVOR_GFM && ($legacy['preserveLineBreaks'] ?? true)
            ? Markdown::FLAVOR_GFM_COMMENT
            : $flavour;

        $settings = [
            'flavor' => $flavour,
            'encode' => (bool) ($legacy['encodeHtml'] ?? false),
            'inlineOnly' => (bool) ($legacy['inlineOnly'] ?? false),
            'showToolbar' => (bool) ($legacy['showToolbar'] ?? true),
            'showStats' => (bool) ($legacy['showStats'] ?? false),
            'sanitizeHtml' => (bool) ($legacy['purifyHtml'] ?? true),
            'placeholder' => self::nullableString($legacy['placeholder'] ?? null),
            'initialRows' => max(1, (int) ($legacy['minRows'] ?? 2)),
            'charLimit' => self::nullableInt($legacy['charLimit'] ?? null),
            'byteLimit' => self::nullableInt($legacy['byteLimit'] ?? null),
            'showUnpermittedVolumes' => (bool) ($legacy['showUnpermittedVolumes'] ?? false),
        ];

        // Buttons, in 1.x's order, without the ones the native toolbar doesn't have
        $buttons = is_array($legacy['toolbarButtons'] ?? null)
            ? $legacy['toolbarButtons']
            : ['h2', 'bold', 'italic', 'strike', 'quote', 'code', 'ul', 'ol', 'link', 'entry', 'asset', 'snippets'];

        $settings['toolbarButtons'] = array_values(array_unique(array_filter(array_map(
            fn (mixed $button) => self::BUTTONS[$button] ?? null,
            $buttons,
        ))));

        // 1.x had a Preview tab rather than a button
        if ($legacy['showPreview'] ?? true) {
            $settings['toolbarButtons'][] = MarkdownField::TOOLBAR_PREVIEW;
        }

        // Link and Entry were separate buttons in 1.x. The native Link button offers
        // the link types the field allows, which is also what turns on `@` links
        $types = array_values(array_filter([
            in_array('entry', $buttons, true) ? 'entry' : null,
            in_array('link', $buttons, true) ? 'url' : null,
        ]));
        $settings['linkSettingsTypes'] = $types !== [] ? $types : MarkdownField::DEFAULT_LINK_TYPES;

        // 1.x chose volumes as `volume:<uid>` sources. The native field takes UIDs
        $volumes = $legacy['availableVolumes'] ?? '*';

        if (is_array($volumes)) {
            $uids = [];

            foreach ($volumes as $source) {
                if (is_string($source) && str_starts_with($source, 'volume:')) {
                    $uids[] = substr($source, 7);
                }
            }

            if (count($uids) < count($volumes)) {
                $notes[] = 'Some of its asset sources were folders rather than whole volumes, which the native field can’t limit to. Check its Asset Volumes setting.';
            }

            $settings['availableVolumes'] = $uids;
        } else {
            $settings['availableVolumes'] = '*';
        }

        if (! ($legacy['parseRefs'] ?? true)) {
            $notes[] = 'It didn’t resolve reference tags. The native field always does.';
        }

        if (! empty($legacy['purifierConfig'])) {
            $notes[] = sprintf('Its HTML Purifier config (%s) doesn’t carry over. The native field uses Craft’s HTML sanitizers: pick one under its HTML Sanitizer setting.', $legacy['purifierConfig']);
        }

        foreach (self::DROPPED as $key => $label) {
            if (array_key_exists($key, $legacy) && $legacy[$key] !== self::DEFAULTS[$key]) {
                $notes[] = sprintf('Its %s setting has no native equivalent, and was left out.', $label);
            }
        }

        return [$settings, $notes];
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private static function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
