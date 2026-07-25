<?php

namespace bensomething\wahlberg;

use bensomething\wahlberg\fields\MarkdownField;
use bensomething\wahlberg\web\assets\editor\EditorAsset;
use Craft;
use craft\helpers\Cp;
use craft\helpers\Html;
use craft\helpers\Json;
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
     * @param array{
     *     name?: string|null,
     *     value?: string,
     *     id?: string|null,
     *     toolbar?: bool,
     *     preview?: bool,
     *     highlight?: bool,
     *     flavour?: string,
     *     fieldUid?: string|null,
     *     fontSize?: int,
     *     minRows?: int,
     *     maxRows?: int|null,
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
            'preview' => true,
            'highlight' => true,
            'flavour' => MarkdownField::FLAVOUR_GFM_COMMENT,
            'fieldUid' => null,
            'fontSize' => MarkdownField::DEFAULT_FONT_SIZE,
            'minRows' => MarkdownField::DEFAULT_MIN_ROWS,
            'maxRows' => null,
            'inputAttributes' => [],
        ];

        $view = Craft::$app->getView();
        $view->registerAssetBundle(EditorAsset::class);
        $view->registerTranslations('wahlberg', [
            'Nothing to preview',
            'The preview couldn’t be loaded.',
            'url',
        ]);

        $id = $config['id'] ?: ($config['name'] ? Html::id((string)$config['name']) : sprintf('wahlberg-%s', mt_rand()));
        $containerId = "$id-editor";

        $flavour = in_array($config['flavour'], MarkdownField::parserFlavours(), true)
            ? $config['flavour']
            : MarkdownField::FLAVOUR_GFM_COMMENT;

        $minRows = max(MarkdownField::MIN_ROWS, (int)$config['minRows']);
        $maxRows = $config['maxRows'] !== null ? max($minRows, (int)$config['maxRows']) : null;

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
            'showToolbar' => (bool)$config['toolbar'],
            'showPreview' => (bool)$config['preview'],
            'highlight' => (bool)$config['highlight'],
            'toolbar' => self::toolbar(),
            'overflowMenu' => $config['toolbar'] ? self::overflowMenuHtml() : null,
            'inputAttributes' => $config['inputAttributes'],
        ], View::TEMPLATE_MODE_CP);
    }

    /**
     * The toolbar buttons, in display order, split into the groups the divider separates.
     *
     * @return array<int, array<int, array<string, string|null>>>
     */
    public static function toolbar(): array
    {
        $button = fn(string $command, string $icon, string $label, ?string $shortcut = null) => [
            'command' => $command,
            'iconName' => $icon,
            'icon' => (string)Cp::iconSvg($icon),
            'label' => $label,
            'shortcut' => $shortcut,
        ];

        return [
            [
                $button('heading', 'heading', Craft::t('wahlberg', 'Heading')),
                $button('bold', 'bold', Craft::t('wahlberg', 'Bold'), 'B'),
                $button('italic', 'italic', Craft::t('wahlberg', 'Italic'), 'I'),
                $button('quote', 'block-quote', Craft::t('wahlberg', 'Quote')),
                $button('code', 'code', Craft::t('wahlberg', 'Code')),
                $button('link', 'link', Craft::t('wahlberg', 'Link'), 'K'),
            ],
            [
                $button('ul', 'list-ul', Craft::t('wahlberg', 'Bulleted list')),
                $button('ol', 'list-ol', Craft::t('wahlberg', 'Numbered list')),
            ],
        ];
    }

    /**
     * The same commands as the toolbar, as a disclosure menu the buttons fold
     * into when the header runs out of room.
     */
    public static function overflowMenuHtml(): string
    {
        $items = [];

        foreach (self::toolbar() as $i => $group) {
            if ($i > 0) {
                $items[] = ['hr' => true];
            }

            foreach ($group as $button) {
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
}
