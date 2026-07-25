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
    public const FLAVOR_GFM = 'gfm';
    public const FLAVOR_GFM_COMMENT = 'gfm-comment';
    public const FLAVOR_ORIGINAL = 'original';
    public const FLAVOR_EXTRA = 'extra';

    public const MIN_FONT_SIZE = 11;
    public const MAX_FONT_SIZE = 20;

    /**
     * @var string The Markdown flavor to parse with
     */
    public string $flavor = self::FLAVOR_GFM;

    /**
     * @var int The editor’s text size, in pixels
     */
    public int $fontSize = 15;

    /**
     * @var int How short the editor is allowed to get, in rows
     */
    public int $minRows = 12;

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

        parent::__construct($config);
    }

    /**
     * The Markdown flavors authors can be given, keyed by the flavor name
     * [[\yii\helpers\BaseMarkdown::$flavors]] knows them by.
     *
     * @return array<string, string>
     */
    public static function flavors(): array
    {
        return [
            self::FLAVOR_GFM => Craft::t('wahlberg', 'GitHub-Flavored Markdown'),
            self::FLAVOR_GFM_COMMENT => Craft::t('wahlberg', 'GitHub-Flavored Markdown (line breaks preserved)'),
            self::FLAVOR_ORIGINAL => Craft::t('wahlberg', 'Traditional Markdown'),
            self::FLAVOR_EXTRA => Craft::t('wahlberg', 'Markdown Extra'),
        ];
    }

    public static function displayName(): string
    {
        return Craft::t('wahlberg', 'Markdown');
    }

    public static function icon(): string
    {
        // Craft bundles Font Awesome’s Markdown mark, but only exposes the solid
        // set by name — so point at the brand icon directly, falling back to a
        // system icon if it ever moves
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
        $rules[] = [['flavor'], 'in', 'range' => array_keys(self::flavors())];
        $rules[] = [['fontSize'], 'integer', 'min' => self::MIN_FONT_SIZE, 'max' => self::MAX_FONT_SIZE];
        $rules[] = [['minRows'], 'integer', 'min' => 3];
        $rules[] = [['maxRows'], 'integer', 'min' => 3, 'skipOnEmpty' => true];
        $rules[] = [['maxRows'], 'compare', 'compareAttribute' => 'minRows', 'operator' => '>=', 'skipOnEmpty' => true];
        $rules[] = [['showToolbar', 'showPreview', 'purifyHtml'], 'boolean'];
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

        return new MarkdownData($value, $this->flavor, $this->purifyHtml, $this->purifierConfig);
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
            'flavor' => $this->flavor,
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
            'label' => Craft::t('wahlberg', 'Markdown Flavor'),
            'instructions' => Craft::t('wahlberg', 'Which parser the Preview tab and `html` value should use.'),
            'id' => 'flavor',
            'name' => 'flavor',
            'value' => $this->flavor,
            'options' => array_map(
                fn(string $value, string $label) => ['label' => $label, 'value' => $value],
                array_keys(self::flavors()),
                array_values(self::flavors()),
            ),
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
            'min' => 3,
            'size' => 3,
            'errors' => $this->getErrors('minRows'),
        ]) . Cp::textFieldHtml([
            'label' => Craft::t('wahlberg', 'Maximum Rows'),
            'instructions' => Craft::t('wahlberg', 'How tall the editor may grow before it starts scrolling instead. Leave blank to let it keep growing.'),
            'id' => 'maxRows',
            'name' => 'maxRows',
            'value' => $this->maxRows,
            'type' => 'number',
            'min' => 3,
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
            'label' => Craft::t('wahlberg', 'Purify HTML'),
            'instructions' => Craft::t('wahlberg', 'Run the parsed HTML through [HTML Purifier](http://htmlpurifier.org/). Markdown lets authors write HTML inline, so leaving this off means whatever they type gets rendered — including scripts. The raw Markdown is never modified.'),
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
