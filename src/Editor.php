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

        // A map of definitions is taken as-is; handles and `*` resolve against
        // the config file
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
        $snippetsButton = self::snippetsButtonHtml($snippets, $buttons);
        $guide = self::guideHtml($buttons);

        // Nothing ticked means no toolbar rather than an empty strip. The menus
        // count, though neither is one of the groups
        $showToolbar = (bool)$config['toolbar'] &&
            ($toolbar !== [] || $snippetsButton !== '' || $guide !== '');

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
                // Bodies only: the labels are already in the menu
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
            'minRows' => $minRows,
            'maxRows' => $maxRows,
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
            'snippetsButton' => $showToolbar ? $snippetsButton : null,
            // Outside the toolbar: the shortcut opens it whether or not the button
            // is there, and it's positioned at the caret rather than at either
            'snippetsMenu' => self::snippetsMenuHtml($snippets),
            'guide' => $showToolbar ? $guide : null,
            'inputAttributes' => $config['inputAttributes'],
        ], View::TEMPLATE_MODE_CP);
    }

    /**
     * The editor’s read-only twin: the same surface and the same type, showing the
     * Markdown as it was written, with no textarea, no toolbar and nothing to run.
     *
     * For anywhere a value is being shown rather than edited — a revision, a
     * disabled form, a slideout that only reports. Craft renders those by disabling
     * a field’s inputs and throwing away the JS registered alongside them, which the
     * editor can’t survive: the textarea paints its own text transparent so the
     * highlighted layer behind it shows through, and that layer is filled by the JS
     * that never ran. What’s left is a box that looks empty. Hence no editor here at
     * all, rather than a disabled one.
     *
     * @param array{
     *     value?: string,
     *     fontSize?: int,
     * } $config
     */
    public static function staticHtml(array $config = []): string
    {
        $config += [
            'value' => '',
            'fontSize' => MarkdownField::DEFAULT_FONT_SIZE,
        ];

        // For the CSS. The bundle brings the editor's JS with it, which has nothing
        // to do here but defines a class and stops
        Craft::$app->getView()->registerAssetBundle(EditorAsset::class);

        $fontSize = min(
            MarkdownField::MAX_FONT_SIZE,
            max(MarkdownField::MIN_FONT_SIZE, (int)$config['fontSize']),
        );

        $text = Html::tag('div', Html::encode((string)$config['value']), [
            'class' => 'wahlberg-static',
        ]);

        return Html::tag('div', Html::tag('div', $text, ['class' => 'wahlberg-editor']), [
            // `--bare` because there's no header for the editor's square top corners
            // to sit under
            'class' => ['wahlberg', 'wahlberg--bare'],
            'style' => ['--wahlberg-font-size' => "{$fontSize}px"],
        ]);
    }

    /**
     * Every formatting command, as `command => label`. This order is the display
     * order, so a field turning half of them off still reads the same way.
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
            'link' => Craft::t('wahlberg', 'Link'),
            'entry' => Craft::t('wahlberg', 'Entry'),
            'asset' => Craft::t('wahlberg', 'Asset'),
            'snippets' => Craft::t('wahlberg', 'Snippets'),
            'guide' => Craft::t('wahlberg', 'Markdown guide'),
        ];
    }

    /**
     * The Snippets button, for the toolbar. The menu it opens is rendered
     * separately, since the shortcut has to work whether or not this is on the
     * toolbar at all.
     *
     * @param array<string, array{label: string, body: string, icon: string|null}> $snippets
     * @param list<string>|null $only Commands the toolbar is showing
     */
    public static function snippetsButtonHtml(array $snippets, ?array $only = null): string
    {
        if ($snippets === [] || ($only !== null && !in_array('snippets', $only, true))) {
            return '';
        }

        $label = Craft::t('wahlberg', 'Snippets');

        return Html::tag('div', Html::button((string)Cp::iconSvg('scissors'), [
            'class' => ['wahlberg-tool', 'wahlberg-snippets-btn'],
            'title' => $label,
            'aria' => ['label' => $label, 'expanded' => 'false'],
            'data' => ['snippets-trigger' => true],
        ]), ['class' => 'wahlberg-snippets']);
    }

    /**
     * The snippets menu, rendered hidden and opened at the caret.
     *
     * Craft's disclosure menu anchors to the button that opens it, which is the
     * wrong place: a snippet goes in where the author is typing, and the shortcut
     * has to work when the button isn't on the toolbar. Same markup Craft's menus
     * use, so the classes carry the styling, but driven by the editor itself.
     *
     * @param array<string, array{label: string, body: string, icon: string|null}> $snippets
     */
    public static function snippetsMenuHtml(array $snippets): string
    {
        if ($snippets === []) {
            return '';
        }

        $items = array_map(function(string $handle, array $snippet) {
            // Everything gets an icon, so the labels line up. Applied here rather
            // than stored, so the config keeps saying what was actually set
            $icon = Html::tag('span', (string)Cp::iconSvg($snippet['icon'] ?? self::DEFAULT_SNIPPET_ICON), [
                'class' => 'icon',
                'aria' => ['hidden' => 'true'],
            ]);

            return Html::tag('li', Html::button($icon . Html::encode($snippet['label']), [
                'class' => 'menu-item',
                'type' => 'button',
                'data' => ['snippet' => $handle],
            ]));
        }, array_keys($snippets), array_values($snippets));

        return Html::tag('div', Html::tag('ul', implode('', $items)), [
            'class' => ['menu', 'menu--disclosure', 'wahlberg-snippet-menu'],
            'data' => ['snippet-menu' => true],
        ]);
    }

    /**
     * The heading control: at most one, whatever’s ticked, since six near-identical
     * H icons is a lot of toolbar to say one thing. One level applies straight
     * away, several open a menu, none shows nothing.
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
                // The plain H whichever level it applies, so the toolbar doesn't
                // shift between fields. The level is in the tooltip
                'iconName' => 'heading',
                'icon' => (string)Cp::iconSvg('heading'),
                'label' => $labels[$command],
                'shortcut' => null,
            ]];
        }

        // The levels, not the menu: rendering one needs the view, and `toolbar()`
        // stays readable without one
        return [['headings' => $levels]];
    }

    /**
     * Renders the menus a toolbar’s entries only describe. Kept out of [[toolbar()]]
     * so that stays callable without a view.
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
     * The buttons in display order, split into the groups the dividers separate.
     * Empty groups are dropped so a divider never hangs on its own.
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
            ],
            [
                $button('link', 'link', 'K'),
                $button('entry', 'newspaper'),
                $button('asset', 'image'),
            ],
        ];

        // `guide` and `snippets` are absent on purpose: each opens something
        // anchored to itself, so both are pinned rather than folding

        if ($only === null) {
            return $groups;
        }

        $groups = array_map(
            fn(array $group) => array_values(array_filter(
                $group,
                // Already cut to the ticked levels, and has no single command
                fn(array $button) => isset($button['headings']) ||
                    in_array($button['command'], $only, true),
            )),
            $groups,
        );

        return array_values(array_filter($groups));
    }

    /**
     * The same commands as a menu, for the buttons to fold into when the header
     * runs out of room.
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
                // A menu of its own, which never folds
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
     * The Markdown guide button and the cheatsheet it opens. Rendered hidden and
     * handed to `Garnish.HUD` on first click — the popover Craft opens off a field’s
     * info icon, anchored to the button rather than stranded under a tall editor.
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
                // `craft-copy-attribute` brings the chip and the clipboard icon
                // with it. It replaces its children on connect, so this can't wrap
                // a `<code>`
                Html::tag('th', Html::tag(
                    'craft-copy-attribute',
                    Html::encode($row['syntax']),
                    ['value' => $row['syntax']],
                ), ['scope' => 'row']),
                // Parsed, so a label can mark up the syntax it names. Our strings
                Html::tag('td', Markdown::processParagraph($row['label'])),
            ])),
            self::guide(),
        );

        // Wrapped, so the divider sits on something other than the button, which
        // is a fixed square with a centred icon
        return Html::tag('div', Html::button((string)Cp::iconSvg('question'), [
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
     * The cheatsheet, as syntax/description pairs. Local rather than a link out: an
     * author shouldn’t have to leave the page, and a control panel behind a firewall
     * shouldn’t be offered a link that won’t load.
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
