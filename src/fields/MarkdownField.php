<?php

namespace bensomething\wahlberg\fields;

use bensomething\wahlberg\Editor;
use bensomething\wahlberg\helpers\Purifier;
use bensomething\wahlberg\models\MarkdownData;
use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\base\MergeableFieldInterface;
use craft\base\SortableFieldInterface;
use craft\gql\GqlEntityRegistry;
use craft\helpers\Cp;
use craft\helpers\Html;
use craft\helpers\StringHelper;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use yii\db\Schema;

class MarkdownField extends Field implements SortableFieldInterface, MergeableFieldInterface
{
    public const FLAVOUR_GFM = 'gfm';

    /**
     * GFM with single newlines turned into `<br>`s. Not a flavour authors pick, but
     * what [[$flavour]] resolves to when [[$preserveLineBreaks]] is on.
     */
    public const FLAVOUR_GFM_COMMENT = 'gfm-comment';

    public const FLAVOUR_ORIGINAL = 'original';
    public const FLAVOUR_EXTRA = 'extra';

    public const MIN_FONT_SIZE = 11;
    public const MAX_FONT_SIZE = 20;

    /**
     * The shortest the editor may be set to. A single row, for a field meant to
     * look like a one-liner and grow only if it has to.
     */
    public const MIN_ROWS = 1;

    /**
     * What a field starts out with, and what [[\bensomething\wahlberg\Editor]]
     * falls back to, so the editor looks the same whether it turns up in a
     * Markdown field or in another plugin rendering one of the macros.
     */
    public const DEFAULT_FONT_SIZE = 14;
    public const DEFAULT_MIN_ROWS = 2;

    /**
     * @var string The Markdown flavour to parse with
     */
    public string $flavour = self::FLAVOUR_GFM;

    /**
     * @var bool Whether a single newline should become a `<br>`, the way GitHub’s
     * comment boxes do. Only GitHub-Flavoured Markdown offers the choice.
     */
    public bool $preserveLineBreaks = true;

    /**
     * @var int The editor’s text size, in pixels
     */
    public int $fontSize = self::DEFAULT_FONT_SIZE;

    /**
     * @var int How short the editor is allowed to get, in rows
     */
    public int $minRows = self::DEFAULT_MIN_ROWS;

    /**
     * @var int|null How tall the editor may grow before it starts scrolling, in
     * rows. Null lets it grow to fit whatever’s typed.
     */
    public ?int $maxRows = null;

    /**
     * @var bool Whether the formatting toolbar should be shown
     */
    public bool $showToolbar = true;

    /**
     * @var bool Whether the Preview tab should be shown
     */
    public bool $showPreview = true;

    /**
     * @var bool Whether the Write tab should colour Markdown syntax. Off leaves a
     * plain textarea with the same chrome, which is the escape hatch when a font
     * stack won’t hold the two layers together.
     */
    public bool $showHighlighting = true;

    /**
     * @var bool Whether reference tags in the parsed HTML should be resolved
     */
    public bool $parseRefs = true;

    /**
     * @var bool Whether the parsed HTML should be run through HTML Purifier
     */
    public bool $purifyHtml = true;

    /**
     * @var string|null The HTML Purifier config file to use
     */
    public ?string $purifierConfig = null;

    public function __construct(array $config = [])
    {
        // `initialRows` became `minRows` when the editor started growing to fit
        if (isset($config['initialRows'])) {
            $config['minRows'] ??= $config['initialRows'];
            unset($config['initialRows']);
        }

        // Spelled `flavor` before the plugin settled on British English
        if (isset($config['flavor'])) {
            $config['flavour'] ??= $config['flavor'];
            unset($config['flavor']);
        }

        // `gfm-comment` was a flavour of its own before preserved line breaks became
        // a setting sitting alongside GFM
        if (($config['flavour'] ?? null) === self::FLAVOUR_GFM_COMMENT) {
            $config['flavour'] = self::FLAVOUR_GFM;
            $config['preserveLineBreaks'] ??= true;
        }

        parent::__construct($config);
    }

    /**
     * The Markdown flavours authors can be given, keyed by the flavour name
     * [[\yii\helpers\BaseMarkdown::$flavors]] knows them by.
     *
     * @return array<string, string>
     */
    public static function flavours(): array
    {
        return [
            self::FLAVOUR_GFM => Craft::t('wahlberg', 'GitHub-Flavoured Markdown'),
            self::FLAVOUR_ORIGINAL => Craft::t('wahlberg', 'Traditional Markdown'),
            self::FLAVOUR_EXTRA => Craft::t('wahlberg', 'Markdown Extra'),
        ];
    }

    /**
     * Every flavour name that can be handed to a parser: [[flavours()]] plus
     * `gfm-comment`, which is a resolved setting rather than one of the choices.
     * Anywhere taking a flavour from outside should validate against this.
     *
     * @return list<string>
     */
    public static function parserFlavours(): array
    {
        return [
            self::FLAVOUR_GFM,
            self::FLAVOUR_GFM_COMMENT,
            self::FLAVOUR_ORIGINAL,
            self::FLAVOUR_EXTRA,
        ];
    }

    /**
     * The flavour to actually parse with: GFM with its line breaks preserved is
     * Yii’s `gfm-comment`.
     */
    public function getParserFlavour(): string
    {
        return $this->flavour === self::FLAVOUR_GFM && $this->preserveLineBreaks
            ? self::FLAVOUR_GFM_COMMENT
            : $this->flavour;
    }

    public static function displayName(): string
    {
        return Craft::t('wahlberg', 'Markdown');
    }

    public static function icon(): string
    {
        // Craft bundles Font Awesome’s Markdown mark but only exposes the solid set
        // by name, so point at the brand icon directly, falling back to a system
        // icon if it ever moves
        $path = Craft::getAlias('@app/icons/brands/markdown.svg', false);

        if (is_string($path) && file_exists($path)) {
            return $path;
        }

        return 'file-lines';
    }

    public static function phpType(): string
    {
        return sprintf('\\%s|null', MarkdownData::class);
    }

    public static function dbType(): array|string|null
    {
        return Schema::TYPE_TEXT;
    }

    public function attributeLabels(): array
    {
        return array_merge(parent::attributeLabels(), [
            'fontSize' => Craft::t('wahlberg', 'Text Size'),
            'minRows' => Craft::t('wahlberg', 'Minimum Rows'),
            'maxRows' => Craft::t('wahlberg', 'Maximum Rows'),
        ]);
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['flavour'], 'in', 'range' => array_keys(self::flavours())];
        $rules[] = [['fontSize'], 'integer', 'min' => self::MIN_FONT_SIZE, 'max' => self::MAX_FONT_SIZE];
        $rules[] = [['minRows'], 'integer', 'min' => self::MIN_ROWS];
        $rules[] = [['maxRows'], 'integer', 'min' => self::MIN_ROWS, 'skipOnEmpty' => true];
        $rules[] = [['maxRows'], 'compare', 'compareAttribute' => 'minRows', 'operator' => '>=', 'skipOnEmpty' => true];
        $rules[] = [['showToolbar', 'showPreview', 'showHighlighting', 'preserveLineBreaks', 'parseRefs', 'purifyHtml'], 'boolean'];
        $rules[] = [['purifierConfig'], 'safe'];
        return $rules;
    }

    public function normalizeValue(mixed $value, ?ElementInterface $element = null): mixed
    {
        if ($value instanceof MarkdownData) {
            return $value;
        }

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return new MarkdownData(
            $value,
            $this->getParserFlavour(),
            $this->purifyHtml,
            $this->purifierConfig,
            $this->parseRefs,
            // So a reference resolves to the URL for the site the element is being
            // rendered in, rather than whichever site happens to be current
            $element?->siteId,
        );
    }

    public function serializeValue(mixed $value, ?ElementInterface $element = null): mixed
    {
        if (!$value instanceof MarkdownData) {
            return null;
        }

        return $value->getRaw();
    }

    public function getSearchKeywords(mixed $value, ElementInterface $element): string
    {
        return $value instanceof MarkdownData ? $value->getText() : '';
    }

    public function getPreviewHtml(mixed $value, ElementInterface $element): string
    {
        if (!$value instanceof MarkdownData) {
            return '';
        }

        return Html::encode(StringHelper::safeTruncate($value->getText(), 100));
    }

    protected function inputHtml(mixed $value, ?ElementInterface $element, bool $inline): string
    {
        return Editor::inputHtml([
            'id' => $this->getInputId(),
            'name' => $this->handle,
            'value' => $value instanceof MarkdownData ? $value->getRaw() : '',
            'toolbar' => $this->showToolbar,
            'preview' => $this->showPreview,
            'highlight' => $this->showHighlighting,
            'flavour' => $this->getParserFlavour(),
            'fieldUid' => $this->uid,
            'fontSize' => $this->fontSize,
            'minRows' => $this->minRows,
            'maxRows' => $this->maxRows,
        ]);
    }

    public function getContentGqlType(): Type|array
    {
        $typeName = 'wahlberg_Markdown';

        return GqlEntityRegistry::getOrCreate($typeName, fn() => new ObjectType([
            'name' => $typeName,
            'fields' => [
                'raw' => [
                    'type' => Type::string(),
                    'description' => 'The raw Markdown, as the author typed it.',
                    'resolve' => fn(MarkdownData $data) => $data->getRaw(),
                ],
                'html' => [
                    'type' => Type::string(),
                    'description' => 'The parsed HTML.',
                    'resolve' => fn(MarkdownData $data) => (string)$data->getHtml(),
                ],
                'text' => [
                    'type' => Type::string(),
                    'description' => 'The parsed HTML with tags stripped.',
                    'resolve' => fn(MarkdownData $data) => $data->getText(),
                ],
            ],
        ]));
    }

    public function getSettingsHtml(): ?string
    {
        return Cp::selectFieldHtml([
            'label' => Craft::t('wahlberg', 'Markdown Flavour'),
            'instructions' => Craft::t('wahlberg', 'Which parser the Preview tab and `html` value should use.'),
            'id' => 'flavour',
            'name' => 'flavour',
            'value' => $this->flavour,
            'options' => array_map(
                fn(string $value, string $label) => ['label' => $label, 'value' => $value],
                array_keys(self::flavours()),
                array_values(self::flavours()),
            ),
            'toggle' => true,
            'targetPrefix' => 'flavour-',
        ]) . Html::tag('div', Cp::lightswitchFieldHtml([
            'label' => Craft::t('wahlberg', 'Preserve Line Breaks'),
            'instructions' => Craft::t('wahlberg', 'Turn a single newline into a `<br>`, the way GitHub’s comment boxes do.'),
            'id' => 'preserveLineBreaks',
            'name' => 'preserveLineBreaks',
            'on' => $this->preserveLineBreaks,
        ]), [
            'id' => 'flavour-' . self::FLAVOUR_GFM,
            'class' => $this->flavour === self::FLAVOUR_GFM ? null : 'hidden',
        ]) . Cp::textFieldHtml([
            'label' => Craft::t('wahlberg', 'Text Size'),
            'instructions' => Craft::t('wahlberg', 'The size of the Markdown source in the editor, in pixels. Doesn’t affect the front end.'),
            'id' => 'fontSize',
            'name' => 'fontSize',
            'value' => $this->fontSize,
            'type' => 'number',
            'min' => self::MIN_FONT_SIZE,
            'max' => self::MAX_FONT_SIZE,
            'size' => 3,
            'errors' => $this->getErrors('fontSize'),
        ]) . Cp::textFieldHtml([
            'label' => Craft::t('wahlberg', 'Minimum Rows'),
            'instructions' => Craft::t('wahlberg', 'How short the editor is allowed to get. It grows as the author types.'),
            'id' => 'minRows',
            'name' => 'minRows',
            'value' => $this->minRows,
            'type' => 'number',
            'min' => self::MIN_ROWS,
            'size' => 3,
            'errors' => $this->getErrors('minRows'),
        ]) . Cp::textFieldHtml([
            'label' => Craft::t('wahlberg', 'Maximum Rows'),
            'instructions' => Craft::t('wahlberg', 'How tall the editor may grow before it starts scrolling instead. Leave blank to let it keep growing.'),
            'id' => 'maxRows',
            'name' => 'maxRows',
            'value' => $this->maxRows,
            'type' => 'number',
            'min' => self::MIN_ROWS,
            'size' => 3,
            'errors' => $this->getErrors('maxRows'),
        ]) . Cp::lightswitchFieldHtml([
            'label' => Craft::t('wahlberg', 'Show Preview Tab'),
            'instructions' => Craft::t('wahlberg', 'Let authors switch between the Markdown source and the rendered result. With this off, the editor is source-only.'),
            'id' => 'showPreview',
            'name' => 'showPreview',
            'on' => $this->showPreview,
        ]) . Cp::lightswitchFieldHtml([
            'label' => Craft::t('wahlberg', 'Show Formatting Toolbar'),
            'instructions' => Craft::t('wahlberg', 'Show the formatting buttons above the editor. Keyboard shortcuts keep working either way.'),
            'id' => 'showToolbar',
            'name' => 'showToolbar',
            'on' => $this->showToolbar,
        ]) . Cp::lightswitchFieldHtml([
            'label' => Craft::t('wahlberg', 'Show Syntax Highlighting'),
            'instructions' => Craft::t('wahlberg', 'Colour the Markdown as it’s typed. With this off, the Write tab is a plain textarea with the same sizing, toolbar and Preview tab.'),
            'id' => 'showHighlighting',
            'name' => 'showHighlighting',
            'on' => $this->showHighlighting,
        ]) . Cp::lightswitchFieldHtml([
            'label' => Craft::t('wahlberg', 'Parse Reference Tags'),
            'instructions' => Craft::t('wahlberg', 'Resolve [reference tags](https://craftcms.com/docs/5.x/system/reference-tags.html) like `{entry:123:url}` when rendering, so links survive a slug change. Tags inside code are left as they were typed.'),
            'id' => 'parseRefs',
            'name' => 'parseRefs',
            'on' => $this->parseRefs,
        ]) . Cp::lightswitchFieldHtml([
            'label' => Craft::t('wahlberg', 'Purify HTML'),
            'instructions' => Craft::t('wahlberg', 'Run the parsed HTML through [HTML Purifier](http://htmlpurifier.org/). Markdown lets authors write HTML inline, so leaving this off means whatever they type gets rendered, scripts included. The raw Markdown is never modified.'),
            'id' => 'purifyHtml',
            'name' => 'purifyHtml',
            'on' => $this->purifyHtml,
            'toggle' => 'purifier-config-container',
        ]) . Html::tag('div', Cp::selectFieldHtml([
            'label' => Craft::t('wahlberg', 'HTML Purifier Config'),
            'instructions' => Craft::t('wahlberg', 'Add a JSON config file to `config/htmlpurifier/` to change what’s allowed through. [View available settings](http://htmlpurifier.org/live/configdoc/plain.html)'),
            'id' => 'purifierConfig',
            'name' => 'purifierConfig',
            'value' => $this->purifierConfig,
            'options' => array_map(
                fn(string $value, string $label) => ['label' => $label, 'value' => $value],
                array_keys(Purifier::configOptions()),
                array_values(Purifier::configOptions()),
            ),
        ]), [
            'id' => 'purifier-config-container',
            'class' => $this->purifyHtml ? null : 'hidden',
        ]);
    }
}
