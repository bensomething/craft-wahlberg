<?php

declare(strict_types=1);

namespace bensomething\wahlberg;

use bensomething\wahlberg\Listeners\AttachSnippets;
use CraftCms\Cms\FieldLayout\Events\FieldLayoutUiResolving;
use CraftCms\Cms\Plugin\Plugin as BasePlugin;
use CraftCms\Cms\Ui\Controls\Table;
use CraftCms\Cms\Ui\Controls\Text;
use CraftCms\Cms\Ui\Enums\ControlMode;
use CraftCms\Cms\Ui\Nodes\Field;
use CraftCms\Cms\Ui\Ui;
use CraftCms\Cms\Ui\UiContext;
use Illuminate\Support\Facades\Config;
use Override;

use function CraftCms\Cms\t;

/**
 * Wahlberg: snippets for Craft's Markdown field, Craft 6 line.
 *
 * Craft 6 ships a Markdown field of its own, with a toolbar, a server-rendered
 * preview and reference tags, so 2.x doesn't bring a field type. It adds snippets to
 * the native field instead, on the `/` menu its text expander already has room for.
 * 1.x, on Craft 5, is the full field.
 *
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '2.0.0';

    public bool $hasCpSettings = true;

    /**
     * Where admin changes are disallowed, the settings screen still opens, read-only,
     * so it's clear which snippets there are.
     */
    public bool $hasReadOnlyCpSettings = true;

    protected array $events = [
        FieldLayoutUiResolving::class => AttachSnippets::class,
    ];

    /**
     * On every CP page, since a Markdown field can turn up in any of them, slideouts
     * included. It does nothing until a snippet is picked.
     */
    protected array $scripts = [
        __DIR__.'/../resources/js/tab-stops.js' => 'js/tab-stops.js',
    ];

    /**
     * Bound here rather than in `boot()`: Craft resolves `$events` listeners before
     * `boot()` runs. Snippets is scoped, so the settings and the registering event
     * are read once per request however many fields a page renders.
     */
    #[Override]
    public function register(): void
    {
        parent::register();

        $this->app->singleton(Settings::class, fn () => $this->getSettings());
        $this->app->scoped(Snippets::class);
    }

    #[Override]
    protected static function createSettings(): Settings
    {
        return new Settings;
    }

    /**
     * A setting `config/craft/wahlberg.php` sets is read-only here, with a warning
     * naming the file: Craft applies the file over the saved value, so editing it
     * here would appear to save and do nothing.
     */
    #[Override]
    public function settingsUi(UiContext $context = new UiContext): ?Ui
    {
        $overrides = Config::get("craft.$this->handle", []);
        $overrides = is_array($overrides) ? $overrides : [];

        $overridable = function (string $setting, Field $field) use ($overrides): Field {
            if (array_key_exists($setting, $overrides)) {
                $field->getControl()?->mode(ControlMode::ReadOnly);
                $field->warning(t('Set by `{setting}` in `config/craft/{handle}.php`, so it can’t be changed here.', [
                    'setting' => $setting,
                    'handle' => $this->handle,
                ], 'wahlberg'));
            }

            return $field;
        };

        return Ui::make([
            $overridable('snippets', Field::make(t('Snippets', category: 'wahlberg'), Table::make('snippets')
                // Widths a column won't shrink below, so on a narrow screen the table
                // scrolls sideways in Craft's scroller instead of wrapping every word
                ->columns([
                    'label' => [
                        'heading' => t('Label', category: 'wahlberg'),
                        'type' => 'singleline',
                        'width' => '10rem',
                    ],
                    'handle' => [
                        'heading' => t('Handle', category: 'wahlberg'),
                        'type' => 'singleline',
                        'code' => true,
                        'placeholder' => t('From the label', category: 'wahlberg'),
                        'width' => '9rem',
                    ],
                    // One line to start with; Craft grows it with what's typed
                    'body' => [
                        'heading' => t('Markdown', category: 'wahlberg'),
                        'type' => 'multiline',
                        'code' => true,
                        'width' => '18rem',
                    ],
                    'fields' => [
                        'heading' => t('Fields', category: 'wahlberg'),
                        'type' => 'singleline',
                        'code' => true,
                        'placeholder' => t('All', category: 'wahlberg'),
                        'width' => '9rem',
                    ],
                ])
                ->allowAdd()
                ->allowDelete()
                ->allowReorder()
                ->addRowLabel(t('Add a snippet', category: 'wahlberg')))
                ->instructions(t('Blocks of Markdown authors can drop into a Markdown field from its snippets menu. In the Markdown, `$1` to `$9` are stops the author moves between with Tab, `${1:like this}` is a stop with text already in it, and `$0` is where the caret ends up. **Fields** takes field handles, separated by commas, to offer a snippet on only those fields.', category: 'wahlberg'))),

            $overridable('trigger', Field::make(t('Menu Trigger', category: 'wahlberg'), Text::make('trigger')
                ->size(2)
                ->maxLength(1)
                ->monospace())
                ->instructions(t('The character that opens the snippets menu, typed at the start of a line or after a space.', category: 'wahlberg'))),
        ]);
    }
}
