<?php

namespace bensomething\wahlberg;

use bensomething\wahlberg\fields\MarkdownField;
use bensomething\wahlberg\helpers\Snippets;
use bensomething\wahlberg\web\assets\editor\EditorAsset;
use Craft;
use craft\helpers\Cp;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\helpers\Markdown;
use craft\web\View;

/**
 * Renders the editor on its own, for anywhere you want it that isn’t a Markdown
 * field: a plugin’s own settings screen, a custom field type, a slideout.
 *
 * ```php
 * echo Editor::inputHtml([
 *     'name' => 'notes',
 *     'value' => $model->notes,
 *     'preview' => false,
 * ]);
 * ```
 *
 * The markup and the JS config this produces are public API. The CSS class names
 * and data attributes inside it are not, so go through this method rather than
 * hand-rolling the markup, or an upgrade will break you.
 */
abstract class Editor
{
    /**
     * The heading levels the toolbar can offer, as `h1`…`h6` commands.
     */
    public const HEADING_LEVELS = [1, 2, 3, 4, 5, 6];

    /**
     * What a snippet that didn’t name an icon gets, so one that hasn’t bothered
     * doesn’t knock the labels around it out of line. Neutral on purpose: it stands
     * in for an icon rather than claiming to mean anything.
     */
    public const DEFAULT_SNIPPET_ICON = 'align-left';


    /**
     * @param array{
     *     name?: string|null,
     *     value?: string,
     *     id?: string|null,
     *     toolbar?: bool,
     *     buttons?: list<string>,
     *     preview?: bool,
     *     highlight?: bool,
     *     stats?: bool,
     *     flavour?: string,
     *     fieldUid?: string|null,
     *     fontSize?: int,
     *     minRows?: int,
     *     maxRows?: int|null,
     *     placeholder?: string|null,
     *     charLimit?: int|null,
     *     byteLimit?: int|null,
     *     refTags?: bool,
     *     assetSources?: list<string>|string,
     *     assetCriteria?: array,
     *     snippets?: array<string, array{label: string, body: string, icon: string|null}>|string|list<string>,
     *     inputAttributes?: array,
     * } $config
     */
    public static function inputHtml(array $config = []): string
    {
        $config += [
            'name' => null,
            'value' => '',
            'id' => null,
            'toolbar' => true,
            'buttons' => MarkdownField::DEFAULT_TOOLBAR_BUTTONS,
            'preview' => true,
            'highlight' => true,
            'stats' => false,
            'flavour' => MarkdownField::FLAVOUR_GFM_COMMENT,
            'fieldUid' => null,
            'fontSize' => MarkdownField::DEFAULT_FONT_SIZE,
            'minRows' => MarkdownField::DEFAULT_MIN_ROWS,
            'maxRows' => null,
            'placeholder' => null,
            'charLimit' => null,
            'byteLimit' => null,
            'refTags' => true,
            'assetSources' => '*',
            'assetCriteria' => [],
            'snippets' => '*',
            'inputAttributes' => [],
        ];

        // Handles, or `*`, resolve against the config file. An array of definitions
        // is taken as-is, so a plugin rendering the editor can supply its own
        $snippets = is_array($config['snippets']) && !array_is_list($config['snippets'])
            ? $config['snippets']
            : Snippets::only($config['snippets']);

        $view = Craft::$app->getView();
        $view->registerAssetBundle(EditorAsset::class);
        $view->registerTranslations('wahlberg', [
            'Nothing to preview',
            'The preview couldn’t be loaded.',
            'url',
            '{n, plural, =1{1 character} other{# characters}}',
            '{n, plural, =1{1 word} other{# words}}',
            '{n, plural, =1{1 line} other{# lines}}',
            '{n} of {limit}',
        ]);

        $id = $config['id'] ?: ($config['name'] ? Html::id((string)$config['name']) : sprintf('wahlberg-%s', mt_rand()));
        $containerId = "$id-editor";

        $flavour = in_array($config['flavour'], MarkdownField::parserFlavours(), true)
            ? $config['flavour']
            : MarkdownField::FLAVOUR_GFM_COMMENT;

        $minRows = max(MarkdownField::MIN_ROWS, (int)$config['minRows']);
        $maxRows = $config['maxRows'] !== null ? max($minRows, (int)$config['maxRows']) : null;

        $buttons = array_values(array_intersect(array_keys(self::commands()), (array)$config['buttons']));
        $toolbar = self::toolbar($buttons);
        $snippetsMenu = self::snippetsMenuHtml($snippets, $buttons);
        $guide = self::guideHtml($buttons);

        // Nothing ticked is the same as no toolbar, rather than an empty strip.
        // The two menus count even though neither is one of the groups
        $showToolbar = (bool)$config['toolbar'] &&
            ($toolbar !== [] || $snippetsMenu !== '' || $guide !== '');

        $view->registerJs(sprintf(
            'new WahlbergEditor(%s, %s);',
            Json::encode('#' . $view->namespaceInputId($containerId)),
            // The preview looks the field’s settings up from the UID rather than
            // trusting anything the browser sends
            Json::encode([
                'fieldUid' => $config['fieldUid'],
                'flavour' => $flavour,
                'minRows' => $minRows,
                'maxRows' => $maxRows,
                'charLimit' => $config['charLimit'] ? (int)$config['charLimit'] : null,
                'byteLimit' => $config['byteLimit'] ? (int)$config['byteLimit'] : null,
                'refTags' => (bool)$config['refTags'],
                'assetSources' => $config['assetSources'],
                'assetCriteria' => (object)$config['assetCriteria'],
                // Bodies only. The labels are already in the menu, and the caret
                // and selection markers are resolved browser-side
                'snippets' => (object)array_map(
                    fn(array $snippet) => $snippet['body'],
                    $snippets,
                ),
                'caret' => Snippets::CARET,
                'selection' => Snippets::SELECTION,
            ]),
        ));

        return $view->renderTemplate('wahlberg/_input.twig', [
            'containerId' => $containerId,
            'id' => $id,
            'name' => $config['name'],
            'value' => (string)$config['value'],
            'rows' => $minRows,
            'fontSize' => min(
                MarkdownField::MAX_FONT_SIZE,
                max(MarkdownField::MIN_FONT_SIZE, (int)$config['fontSize']),
            ),
            'showToolbar' => $showToolbar,
            'showPreview' => (bool)$config['preview'],
            'highlight' => (bool)$config['highlight'],
            'showStats' => (bool)$config['stats'],
            'placeholder' => $config['placeholder'],
            'toolbar' => self::renderMenus($toolbar),
            'overflowMenu' => $showToolbar ? self::overflowMenuHtml($buttons) : null,
            'snippetsMenu' => $showToolbar ? $snippetsMenu : null,
            'guide' => $showToolbar ? $guide : null,
            'inputAttributes' => $config['inputAttributes'],
        ], View::TEMPLATE_MODE_CP);
    }

    /**
     * Every formatting command the toolbar can offer, as `command => label`.
     *
     * The order here is the order they appear in, so a field turning half of them
     * off still gets a toolbar that reads the way this one does.
     *
     * @return array<string, string>
     */
    public static function commands(): array
    {
        $commands = [];

        foreach (self::HEADING_LEVELS as $level) {
            $commands["h$level"] = Craft::t('wahlberg', 'Heading {level}', ['level' => $level]);
        }

        return $commands + [
            'bold' => Craft::t('wahlberg', 'Bold'),
            'italic' => Craft::t('wahlberg', 'Italic'),
            'strike' => Craft::t('wahlberg', 'Strikethrough'),
            'quote' => Craft::t('wahlberg', 'Quote'),
            'code' => Craft::t('wahlberg', 'Code'),
            'ul' => Craft::t('wahlberg', 'Bulleted list'),
            'ol' => Craft::t('wahlberg', 'Numbered list'),
            'tasklist' => Craft::t('wahlberg', 'Task list'),
            'link' => Craft::t('wahlberg', 'Link'),
            'entry' => Craft::t('wahlberg', 'Entry'),
            'asset' => Craft::t('wahlberg', 'Asset'),
            'snippets' => Craft::t('wahlberg', 'Snippets'),
            'guide' => Craft::t('wahlberg', 'Markdown guide'),
        ];
    }

    /**
     * The Snippets button, as a menu of whatever’s been defined.
     *
     * Nothing at all when there are none: an installation with no
     * `config/wahlberg.php` shouldn’t be showing authors a button that opens an
     * empty menu, and the field settings hide the section to match.
     *
     * @param array<string, array{label: string, body: string, icon: string|null}> $snippets
     * @param list<string>|null $only Commands the toolbar is showing
     */
    public static function snippetsMenuHtml(array $snippets, ?array $only = null): string
    {
        if ($snippets === [] || ($only !== null && !in_array('snippets', $only, true))) {
            return '';
        }

        $items = array_map(fn(string $handle, array $snippet) => [
            'label' => $snippet['label'],
            // Everything gets one, so the labels line up in a column whether or not
            // a snippet named an icon. Applied here rather than stored, so the
            // config keeps saying what was actually set
            'icon' => $snippet['icon'] ?? self::DEFAULT_SNIPPET_ICON,
            'attributes' => [
                'type' => 'button',
                'data' => ['snippet' => $handle],
            ],
        ], array_keys($snippets), array_values($snippets));

        $label = Craft::t('wahlberg', 'Snippets');

        return Html::tag('div', Cp::disclosureMenu($items, [
            'buttonHtml' => (string)Cp::iconSvg('scissors'),
            'buttonAttributes' => [
                'class' => ['wahlberg-tool', 'wahlberg-more'],
                'title' => $label,
                'aria' => ['label' => $label],
            ],
        ]), ['class' => 'wahlberg-snippets', 'data' => ['snippets' => true]]);
    }

    /**
     * The heading control: always at most one, whatever’s been ticked.
     *
     * Six near-identical H icons in a row is a lot of toolbar to say one thing, so
     * the levels an author is allowed decide the shape of a single control rather
     * than each earning a button. One level applies straight away, several open a
     * menu, none leaves the toolbar without a heading control at all.
     *
     * @param list<string>|null $only Commands to keep, or null for all of them
     * @return array<int, array<string, mixed>>
     */
    private static function headingButtons(?array $only): array
    {
        $labels = self::commands();

        $levels = array_values(array_filter(
            self::HEADING_LEVELS,
            fn(int $level) => $only === null || in_array("h$level", $only, true),
        ));

        if ($levels === []) {
            return [];
        }

        if (count($levels) === 1) {
            $command = 'h' . $levels[0];

            return [[
                'command' => $command,
                // The plain H, not the numbered one. The toolbar shows the same
                // mark whichever level a field was set to, and which level it is
                // belongs in the tooltip rather than in an icon an author has to
                // read the small print of
                'iconName' => 'heading',
                'icon' => (string)Cp::iconSvg('heading'),
                'label' => $labels[$command],
                'shortcut' => null,
            ]];
        }

        // The levels, not the menu they'll become. Rendering one needs the view,
        // and `toolbar()` stays data so it can be read without one
        return [['headings' => $levels]];
    }

    /**
     * Renders the menus a toolbar’s entries only describe, leaving the buttons
     * alone. Kept out of [[toolbar()]] so that stays a plain description of what a
     * field offers rather than something that needs a view to call.
     *
     * @param array<int, array<int, array<string, mixed>>> $groups
     * @return array<int, array<int, array<string, mixed>>>
     */
    private static function renderMenus(array $groups): array
    {
        return array_map(
            fn(array $group) => array_map(
                fn(array $button) => isset($button['headings'])
                    ? $button + ['menu' => self::headingMenuHtml($button['headings'])]
                    : $button,
                $group,
            ),
            $groups,
        );
    }

    /**
     * @param list<int> $levels
     */
    private static function headingMenuHtml(array $levels): string
    {
        $labels = self::commands();

        $items = array_map(fn(int $level) => [
            'label' => $labels["h$level"],
            'icon' => "h$level",
            'attributes' => [
                'type' => 'button',
                'data' => ['command' => "h$level"],
            ],
        ], $levels);

        $label = Craft::t('wahlberg', 'Heading');

        return Html::tag('div', Cp::disclosureMenu($items, [
            'buttonHtml' => (string)Cp::iconSvg('heading'),
            'buttonAttributes' => [
                'class' => ['wahlberg-tool', 'wahlberg-more'],
                'title' => $label,
                'aria' => ['label' => $label],
            ],
        ]), ['class' => 'wahlberg-headings', 'data' => ['headings' => true]]);
    }

    /**
     * The toolbar buttons, in display order, split into the groups the divider
     * separates. Empty groups are dropped, so a divider never hangs on its own.
     *
     * Every entry is a button, except the heading control when more than one level
     * is available, which is a menu carried as pre-rendered `menu` HTML.
     *
     * @param list<string>|null $only Commands to keep, or null for all of them
     * @return array<int, array<int, array<string, string|null>>>
     */
    public static function toolbar(?array $only = null): array
    {
        $labels = self::commands();

        $button = function(string $command, string $icon, ?string $shortcut = null) use ($labels) {
            return [
                'command' => $command,
                'iconName' => $icon,
                'icon' => (string)Cp::iconSvg($icon),
                'label' => $labels[$command],
                'shortcut' => $shortcut,
            ];
        };

        $groups = [
            [
                ...self::headingButtons($only),
                $button('bold', 'bold', 'B'),
                $button('italic', 'italic', 'I'),
                $button('strike', 'strikethrough'),
                $button('quote', 'block-quote'),
                $button('code', 'code'),
            ],
            [
                $button('ul', 'list-ul'),
                $button('ol', 'list-ol'),
                $button('tasklist', 'list-check'),
            ],
            [
                $button('link', 'link', 'K'),
                $button('entry', 'newspaper'),
                $button('asset', 'image'),
            ],
        ];

        // `guide` and `snippets` are deliberately absent: each opens something
        // anchored to itself, so both are pinned beside the overflow button rather
        // than folding into it. See `guideHtml()` and `snippetsMenuHtml()`.

        if ($only === null) {
            return $groups;
        }

        $groups = array_map(
            fn(array $group) => array_values(array_filter(
                $group,
                // The heading control has already been cut to the levels that were
                // ticked, and carries no single command to check
                fn(array $button) => isset($button['headings']) ||
                    in_array($button['command'], $only, true),
            )),
            $groups,
        );

        return array_values(array_filter($groups));
    }

    /**
     * The same commands as the toolbar, as a disclosure menu the buttons fold
     * into when the header runs out of room.
     *
     * @param list<string>|null $only Commands to keep, or null for all of them
     */
    public static function overflowMenuHtml(?array $only = null): string
    {
        $items = [];

        foreach (self::toolbar($only) as $i => $group) {
            if ($i > 0) {
                $items[] = ['hr' => true];
            }

            foreach ($group as $button) {
                // A menu of its own, which never folds, so it has nothing to
                // contribute here
                if (isset($button['headings'])) {
                    continue;
                }

                $items[] = [
                    'label' => $button['label'],
                    'icon' => $button['iconName'],
                    'attributes' => [
                        'type' => 'button',
                        'data' => ['command' => $button['command']],
                    ],
                    'liAttributes' => [
                        'class' => 'hidden',
                        'data' => ['command-item' => $button['command']],
                    ],
                ];
            }
        }

        return Cp::disclosureMenu($items, [
            'buttonHtml' => (string)Cp::iconSvg('ellipsis'),
            'buttonAttributes' => [
                'class' => ['wahlberg-tool', 'wahlberg-more'],
                'title' => Craft::t('wahlberg', 'More formatting'),
                'aria' => ['label' => Craft::t('wahlberg', 'More formatting')],
            ],
        ]);
    }

    /**
     * The Markdown guide button, and the cheatsheet it opens.
     *
     * The panel is rendered hidden and handed to `Garnish.HUD` on first click,
     * which is the popover Craft opens off a field’s info icon: arrow pointing back
     * at the button, positioned against the viewport, closing on Escape or a click
     * outside. Anchoring it to the button is the point — a bar under the editor is
     * a long way from the toolbar once there’s more than a paragraph in the field.
     *
     * @param list<string>|null $only Commands the toolbar is showing
     */
    public static function guideHtml(?array $only = null): string
    {
        if ($only !== null && !in_array('guide', $only, true)) {
            return '';
        }

        $label = Craft::t('wahlberg', 'Markdown guide');

        $rows = array_map(
            fn(array $row) => Html::tag('tr', implode('', [
                // A row header rather than a plain cell: the syntax is what names
                // the row, and it saves the table needing a header row to be read.
                //
                // `craft-copy-attribute` is Craft's own copy-to-clipboard element,
                // which brings the chip, the clipboard icon and the announcement
                // with it. It replaces its children with a button of its own on
                // connect, so there's no wrapping this around a `<code>`
                Html::tag('th', Html::tag(
                    'craft-copy-attribute',
                    Html::encode($row['syntax']),
                    ['value' => $row['syntax']],
                ), ['scope' => 'row']),
                // Parsed, so a label can mark up the bit of syntax it's naming.
                // These are the plugin's own strings, not anything an author typed
                Html::tag('td', Markdown::processParagraph($row['label'])),
            ])),
            self::guide(),
        );

        // Wrapped, so the divider before it can sit on something other than the
        // button, which is a fixed 28px square with its icon centred in it
        return Html::tag('div', Html::button((string)Cp::iconSvg('circle-question'), [
            'class' => ['wahlberg-tool', 'wahlberg-guide-btn'],
            'title' => $label,
            'aria' => ['label' => $label, 'expanded' => 'false'],
            'data' => ['guide-trigger' => true],
        ]), ['class' => 'wahlberg-guide-wrap']) . Html::tag(
            'div',
            Html::tag(
                'table',
                Html::tag('tbody', implode('', $rows)),
                ['class' => 'wahlberg-guide-table'],
            ),
            ['class' => 'wahlberg-guide', 'data' => ['guide' => true], 'hidden' => true],
        );
    }

    /**
     * The cheatsheet the Markdown guide button opens, as syntax/description pairs.
     *
     * Deliberately short and local rather than a link out to a third-party site:
     * an author wanting to remember how a link is written shouldn’t have to leave
     * the page, and a control panel behind a firewall shouldn’t be offering them a
     * link that won’t load.
     *
     * @return array<int, array{syntax: string, label: string}>
     */
    public static function guide(): array
    {
        $rows = [
            '# Heading' => Craft::t('wahlberg', 'Heading, one `#` per level'),
            '**bold**' => Craft::t('wahlberg', 'Bold'),
            '_italic_' => Craft::t('wahlberg', 'Italic'),
            '~~strikethrough~~' => Craft::t('wahlberg', 'Strikethrough'),
            '`code`' => Craft::t('wahlberg', 'Inline code'),
            '```' => Craft::t('wahlberg', 'Fenced code block, on its own line'),
            '[text](https://…)' => Craft::t('wahlberg', 'Link'),
            '![alt](https://…)' => Craft::t('wahlberg', 'Image'),
            '- item' => Craft::t('wahlberg', 'Bulleted list'),
            '1. item' => Craft::t('wahlberg', 'Numbered list'),
            '- [ ] task' => Craft::t('wahlberg', 'Task list'),
            '> quote' => Craft::t('wahlberg', 'Blockquote'),
            '---' => Craft::t('wahlberg', 'Horizontal rule'),
        ];

        return array_map(
            fn(string $syntax, string $label) => ['syntax' => $syntax, 'label' => $label],
            array_keys($rows),
            array_values($rows),
        );
    }
}
