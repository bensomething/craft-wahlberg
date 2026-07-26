<?php

namespace bensomething\wahlberg\fields;

use bensomething\wahlberg\Editor;
use bensomething\wahlberg\helpers\Purifier;
use bensomething\wahlberg\helpers\Snippets;
use bensomething\wahlberg\models\MarkdownData;
use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\base\MergeableFieldInterface;
use craft\base\SortableFieldInterface;
use craft\elements\Asset;
use craft\gql\GqlEntityRegistry;
use craft\helpers\Cp;
use craft\helpers\Html;
use craft\helpers\StringHelper;
use craft\services\ElementSources;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use yii\db\Schema;
use yii\validators\StringValidator;

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

    /**
     * Traditional Markdown, minus the escaping it would otherwise do inside code.
     * Not a flavour authors pick, but what [[$flavour]] resolves to when
     * [[$encodeHtml]] is on and the text reaching the parser is already encoded.
     */
    public const FLAVOUR_PRE_ENCODED = 'pre-encoded';

    public const MIN_FONT_SIZE = 11;
    public const MAX_FONT_SIZE = 20;

    public const LIMIT_UNIT_CHARS = 'chars';
    public const LIMIT_UNIT_BYTES = 'bytes';

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
     * The toolbar a field gets before anyone touches the setting.
     *
     * Everything [[\bensomething\wahlberg\Editor::commands()]] offers bar the
     * headings, where only level 2 is on: the page’s level 1 is nearly always the
     * element’s own title, so body content starts below it. One level means the
     * toolbar gets a button that applies it outright rather than a menu, which is
     * the right default for a field nobody has configured yet.
     *
     * @var list<string>
     */
    public const DEFAULT_TOOLBAR_BUTTONS = [
        'h2', 'bold', 'italic', 'strike', 'quote', 'code',
        'ul', 'ol', 'tasklist', 'link', 'entry', 'asset', 'snippets', 'guide',
    ];

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
     * @var bool Whether the value should render as inline content, without the
     * paragraph tag wrapped around it. For headings, straplines and anything else
     * dropped straight into markup of its own.
     */
    public bool $inlineOnly = false;

    /**
     * @var bool Whether HTML should be encoded before the Markdown is parsed, so a
     * tag an author types comes out as visible text.
     *
     * Distinct from [[$purifyHtml]], which parses the HTML and then drops what
     * isn’t safe. This never lets it be HTML in the first place.
     */
    public bool $encodeHtml = false;

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
     * @var string|null Placeholder text shown while the field is empty
     */
    public ?string $placeholder = null;

    /**
     * @var bool Whether the formatting toolbar should be shown
     */
    public bool $showToolbar = true;

    /**
     * @var list<string> Which formatting buttons the toolbar offers, out of
     * [[Editor::commands()]]. Order and grouping come from there rather than from
     * here, so a toolbar stays legible however it’s been cut down.
     */
    public array $toolbarButtons = self::DEFAULT_TOOLBAR_BUTTONS;

    /**
     * @var bool Whether the editor should show character, word and line counts
     */
    public bool $showStats = false;

    /**
     * @var int|null The most characters the field will accept
     */
    public ?int $charLimit = null;

    /**
     * @var int|null The most bytes the field will accept. Distinct from
     * [[$charLimit]] once the text stops being ASCII: an emoji is one character
     * and four bytes.
     */
    public ?int $byteLimit = null;

    /**
     * @var string|list<string> Which of the snippets defined in
     * `config/wahlberg.php` the Snippets button offers, or `*` for all of them.
     *
     * A field that has never been saved against a snippet gets all of them, so
     * adding one to the config file puts it in front of authors without every
     * field having to be edited.
     */
    public string|array $availableSnippets = '*';

    /**
     * @var string|list<string> The volumes the Asset button may pick from, as
     * source keys, or `*` for all of them.
     */
    public string|array $availableVolumes = '*';

    /**
     * @var bool Whether volumes the author can’t view should still be offered
     */
    public bool $showUnpermittedVolumes = false;

    /**
     * @var bool Whether files uploaded by other authors should still be offered
     */
    public bool $showUnpermittedFiles = false;

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

        // The settings screen posts one limit plus the units it’s counted in, the
        // way Plain Text does, since an author sets one or the other and never both
        if (array_key_exists('fieldLimit', $config)) {
            $limit = (int)$config['fieldLimit'] ?: null;

            if (($config['limitUnit'] ?? self::LIMIT_UNIT_CHARS) === self::LIMIT_UNIT_BYTES) {
                $config['byteLimit'] = $limit;
                $config['charLimit'] = null;
            } else {
                $config['charLimit'] = $limit;
                $config['byteLimit'] = null;
            }

            unset($config['fieldLimit'], $config['limitUnit']);
        }

        // An empty toolbar is what `showToolbar` is for, and a posted form with
        // nothing ticked arrives as '' rather than []
        if (isset($config['toolbarButtons']) && !is_array($config['toolbarButtons'])) {
            $config['toolbarButtons'] = [];
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
            self::FLAVOUR_PRE_ENCODED,
        ];
    }

    /**
     * The flavour to actually parse with: GFM with its line breaks preserved is
     * Yii’s `gfm-comment`.
     *
     * Encoding forces Craft’s `pre-encoded` parser, which is Traditional Markdown
     * with the escaping it would do inside code taken out. The text arriving has
     * already been encoded, so a fenced block run through a normal parser would
     * come out showing `&amp;lt;` where the author typed `<`.
     */
    public function getParserFlavour(): string
    {
        if ($this->encodeHtml) {
            return self::FLAVOUR_PRE_ENCODED;
        }

        return $this->flavour === self::FLAVOUR_GFM && $this->preserveLineBreaks
            ? self::FLAVOUR_GFM_COMMENT
            : $this->flavour;
    }

    /**
     * The units the field’s limit is counted in, for the settings screen.
     *
     * @return array<string, string>
     */
    public static function limitUnits(): array
    {
        return [
            self::LIMIT_UNIT_CHARS => Craft::t('wahlberg', 'Characters'),
            self::LIMIT_UNIT_BYTES => Craft::t('wahlberg', 'Bytes'),
        ];
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
            'charLimit' => Craft::t('wahlberg', 'Field Limit'),
            'byteLimit' => Craft::t('wahlberg', 'Field Limit'),
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
        $rules[] = [['charLimit', 'byteLimit'], 'integer', 'min' => 1];
        $rules[] = [
            [
                'showToolbar', 'showPreview', 'showHighlighting', 'showStats',
                'preserveLineBreaks', 'inlineOnly', 'encodeHtml', 'parseRefs',
                'purifyHtml', 'showUnpermittedVolumes', 'showUnpermittedFiles',
            ],
            'boolean',
        ];
        $rules[] = [['toolbarButtons'], 'each', 'rule' => ['in', 'range' => array_keys(Editor::commands())]];
        $rules[] = [['placeholder', 'purifierConfig', 'availableVolumes', 'availableSnippets'], 'safe'];
        return $rules;
    }

    public function getElementValidationRules(): array
    {
        $rules = parent::getElementValidationRules();

        if ($this->charLimit || $this->byteLimit) {
            $rules[] = ['validateLength'];
        }

        return $rules;
    }

    /**
     * Holds the value to the field’s limit.
     *
     * Counts the raw Markdown, since that’s what the author is typing and what the
     * column has to hold. A string validator can’t be handed straight to
     * [[getElementValidationRules()]] the way Plain Text does it, because the value
     * here is a [[MarkdownData]] rather than a string.
     *
     * The message is the validator’s own, formatted against the field’s label
     * rather than through [[\yii\validators\Validator::validate()]], which hard-codes
     * “the input value” for a value validated outside a model. That way a Markdown
     * field over its limit reads exactly as a Plain Text field over its own, in
     * whatever language the control panel is in.
     */
    public function validateLength(ElementInterface $element): void
    {
        $attribute = "field:$this->handle";
        $value = $element->getFieldValue($this->handle);
        $raw = $value instanceof MarkdownData ? $value->getRaw() : (string)$value;

        $max = $this->byteLimit ?? $this->charLimit;

        $validator = new StringValidator([
            'max' => $max,
            'encoding' => $this->byteLimit ? '8bit' : 'UTF-8',
        ]);

        if ($validator->validate($raw)) {
            return;
        }

        $element->addError($attribute, Craft::$app->getI18n()->format(
            $validator->tooLong,
            [
                'attribute' => $element->getAttributeLabel($attribute),
                'max' => $max,
            ],
            Craft::$app->language,
        ));
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
            $this->inlineOnly,
            $this->encodeHtml,
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
            'buttons' => $this->toolbarButtons,
            'preview' => $this->showPreview,
            'highlight' => $this->showHighlighting,
            'stats' => $this->showStats,
            'flavour' => $this->getParserFlavour(),
            'fieldUid' => $this->uid,
            'fontSize' => $this->fontSize,
            'minRows' => $this->minRows,
            'maxRows' => $this->maxRows,
            'placeholder' => $this->placeholder,
            'charLimit' => $this->charLimit,
            'byteLimit' => $this->byteLimit,
            // So the Asset button writes `{asset:1:url}` rather than a URL that a
            // re-upload would break
            'refTags' => $this->parseRefs,
            'assetSources' => $this->assetSources(),
            'assetCriteria' => $this->assetCriteria(),
            'snippets' => Snippets::only($this->availableSnippets),
        ]);
    }

    /**
     * The volumes the Asset button’s element selector may pick from, as source keys.
     *
     * Resolved to a list even when the setting is `*`, so the permission filter
     * below has something to filter and the browser is never handed a wildcard to
     * interpret for itself.
     *
     * @return list<string>
     */
    private function assetSources(): array
    {
        if (is_array($this->availableVolumes)) {
            $sources = array_values($this->availableVolumes);
        } else {
            $sources = [];

            foreach (Craft::$app->getElementSources()->getSources(Asset::class) as $source) {
                if ($source['type'] !== ElementSources::TYPE_HEADING) {
                    $sources[] = $source['key'];
                }
            }
        }

        if ($this->showUnpermittedVolumes || $sources === []) {
            return $sources;
        }

        $user = Craft::$app->getUser();

        return array_values(array_filter($sources, static function(string $source) use ($user): bool {
            // Anything that isn’t a volume folder isn’t ours to gate
            if (!str_starts_with($source, 'volume:')) {
                return true;
            }

            return $user->checkPermission('viewAssets:' . explode(':', $source)[1]);
        }));
    }

    /**
     * @return array<string, mixed>
     */
    private function assetCriteria(): array
    {
        $criteria = [];

        // Null rather than absent: it clears the restriction the index would
        // otherwise apply for authors without the peer-files permission
        if ($this->showUnpermittedFiles) {
            $criteria['uploaderId'] = null;
        }

        return $criteria;
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
        $options = fn(array $map) => array_map(
            fn(string $value, string $label) => ['label' => $label, 'value' => $value],
            array_keys($map),
            array_values($map),
        );

        // -- Flavour ---------------------------------------------------------

        $html = Cp::selectFieldHtml([
            'label' => Craft::t('wahlberg', 'Markdown Flavour'),
            'instructions' => Craft::t('wahlberg', 'Which parser the Preview tab and `html` value should use.'),
            'id' => 'flavour',
            'name' => 'flavour',
            'value' => $this->flavour,
            'options' => $options(self::flavours()),
            'toggle' => true,
            'targetPrefix' => 'flavour-',
            // Encoding forces the parser, so there’s nothing left to choose
            'disabled' => $this->encodeHtml,
        ]) . Html::tag('div', Cp::lightswitchFieldHtml([
            'label' => Craft::t('wahlberg', 'Preserve Line Breaks'),
            'instructions' => Craft::t('wahlberg', 'Turn a single newline into a `<br>`, the way GitHub’s comment boxes do.'),
            'id' => 'preserveLineBreaks',
            'name' => 'preserveLineBreaks',
            'on' => $this->preserveLineBreaks,
        ]), [
            'id' => 'flavour-' . self::FLAVOUR_GFM,
            'class' => $this->flavour === self::FLAVOUR_GFM ? null : 'hidden',
        ]) . Cp::lightswitchFieldHtml([
            'label' => Craft::t('wahlberg', 'Inline Only'),
            'instructions' => Craft::t('wahlberg', 'Render the value as inline content, without the wrapping `<p>`. For headings, straplines and anything else going straight into markup of its own.'),
            'id' => 'inlineOnly',
            'name' => 'inlineOnly',
            'on' => $this->inlineOnly,
        ]);

        // -- Appearance ------------------------------------------------------

        $html .= Html::tag('hr') . Html::tag('h2', Craft::t('wahlberg', 'Appearance')) . Cp::textFieldHtml([
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
        ]) . Cp::textFieldHtml([
            'label' => Craft::t('wahlberg', 'Placeholder Text'),
            'instructions' => Craft::t('wahlberg', 'Shown in the editor while the field is empty.'),
            'id' => 'placeholder',
            'name' => 'placeholder',
            'value' => $this->placeholder,
            'errors' => $this->getErrors('placeholder'),
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
            'toggle' => 'toolbar-buttons-container',
        ]) . Html::tag('div', Cp::checkboxSelectFieldHtml([
            'label' => Craft::t('wahlberg', 'Toolbar Buttons'),
            'instructions' => Craft::t('wahlberg', 'Which buttons the toolbar offers. They keep the order and grouping above however many are turned off, and fold into a menu when the editor is too narrow to hold them.'),
            'id' => 'toolbarButtons',
            'name' => 'toolbarButtons',
            'options' => $options(Editor::commands()),
            'values' => $this->toolbarButtons,
            'errors' => $this->getErrors('toolbarButtons'),
        ]), [
            'id' => 'toolbar-buttons-container',
            'class' => $this->showToolbar ? null : 'hidden',
        ]) . Cp::lightswitchFieldHtml([
            'label' => Craft::t('wahlberg', 'Show Syntax Highlighting'),
            'instructions' => Craft::t('wahlberg', 'Colour the Markdown as it’s typed. With this off, the Write tab is a plain textarea with the same sizing, toolbar and Preview tab.'),
            'id' => 'showHighlighting',
            'name' => 'showHighlighting',
            'on' => $this->showHighlighting,
        ]) . Cp::lightswitchFieldHtml([
            'label' => Craft::t('wahlberg', 'Show Stats'),
            'instructions' => Craft::t('wahlberg', 'Show character, word and line counts under the editor, along with the field limit if there is one.'),
            'id' => 'showStats',
            'name' => 'showStats',
            'on' => $this->showStats,
        ]) . $this->limitFieldHtml();

        // -- Parsing ---------------------------------------------------------

        $html .= Html::tag('hr') . Html::tag('h2', Craft::t('wahlberg', 'Parsing')) . Cp::lightswitchFieldHtml([
            'label' => Craft::t('wahlberg', 'Parse Reference Tags'),
            'instructions' => Craft::t('wahlberg', 'Resolve [reference tags](https://craftcms.com/docs/5.x/system/reference-tags.html) like `{entry:123:url}` when rendering, so links survive a slug change. Tags inside code are left as they were typed.'),
            'id' => 'parseRefs',
            'name' => 'parseRefs',
            'on' => $this->parseRefs,
        ]) . Cp::lightswitchFieldHtml([
            'label' => Craft::t('wahlberg', 'Encode HTML'),
            'instructions' => Craft::t('wahlberg', 'Encode HTML before the Markdown is parsed, so a tag an author types shows up as text rather than as markup. Stricter than purifying, which parses the HTML first and then drops what isn’t safe. Enabling this enforces Traditional Markdown.'),
            'id' => 'encodeHtml',
            'name' => 'encodeHtml',
            'on' => $this->encodeHtml,
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
            'options' => $options(Purifier::configOptions()),
        ]), [
            'id' => 'purifier-config-container',
            'class' => $this->purifyHtml ? null : 'hidden',
        ]);

        // -- Snippets --------------------------------------------------------
        //
        // Only when there are some. A section explaining that a config file this
        // installation hasn't got could define something is documentation, and
        // documentation doesn't belong in a settings screen

        $snippets = Snippets::options();

        if ($snippets !== []) {
            $html .= Html::tag('hr') . Html::tag('h2', Craft::t('wahlberg', 'Snippets')) . Cp::checkboxSelectFieldHtml([
                'label' => Craft::t('wahlberg', 'Available Snippets'),
                'instructions' => Craft::t('wahlberg', 'Which of the snippets defined in `config/wahlberg.php` this field’s Snippets button offers.'),
                'id' => 'availableSnippets',
                'name' => 'availableSnippets',
                'options' => $options($snippets),
                'values' => $this->availableSnippets,
                'showAllOption' => true,
                'errors' => $this->getErrors('availableSnippets'),
            ]);
        }

        // -- Assets ----------------------------------------------------------

        $html .= Html::tag('hr') . Html::tag('h2', Craft::t('wahlberg', 'Assets')) . $this->volumesFieldHtml() . Cp::lightswitchFieldHtml([
            'label' => Craft::t('wahlberg', 'Show unpermitted volumes'),
            'instructions' => Craft::t('wahlberg', 'Whether to show volumes the author doesn’t have permission to view.'),
            'id' => 'showUnpermittedVolumes',
            'name' => 'showUnpermittedVolumes',
            'on' => $this->showUnpermittedVolumes,
        ]) . Cp::lightswitchFieldHtml([
            'label' => Craft::t('wahlberg', 'Show unpermitted files'),
            'instructions' => Craft::t('wahlberg', 'Whether to show files the author doesn’t have permission to view, per the “View files uploaded by other users” permission.'),
            'id' => 'showUnpermittedFiles',
            'name' => 'showUnpermittedFiles',
            'on' => $this->showUnpermittedFiles,
        ]);

        return $html;
    }

    /**
     * The Available Volumes setting, as the same all-or-some checkbox list Craft’s
     * own element fields use for their sources.
     */
    private function volumesFieldHtml(): string
    {
        $options = [];

        foreach (Craft::$app->getElementSources()->getSources(Asset::class) as $source) {
            if ($source['type'] !== ElementSources::TYPE_HEADING) {
                $options[] = ['label' => $source['label'], 'value' => $source['key']];
            }
        }

        if ($options === []) {
            return Cp::fieldHtml(
                Html::tag('p', Craft::t('wahlberg', 'No volumes exist yet.'), ['class' => 'error']),
                ['label' => Craft::t('wahlberg', 'Available Volumes')],
            );
        }

        return Cp::checkboxSelectFieldHtml([
            'label' => Craft::t('wahlberg', 'Available Volumes'),
            'instructions' => Craft::t('wahlberg', 'Which volumes the Asset button may pick from.'),
            'id' => 'availableVolumes',
            'name' => 'availableVolumes',
            'options' => $options,
            'values' => $this->availableVolumes,
            'showAllOption' => true,
            'errors' => $this->getErrors('availableVolumes'),
        ]);
    }

    /**
     * The Field Limit setting: one number, plus the units it’s counted in.
     *
     * Posted as `fieldLimit` and `limitUnit` and split into [[$charLimit]] and
     * [[$byteLimit]] on the way in, which is how Plain Text does it, so the two
     * fields read the same in the CP and in project config.
     */
    private function limitFieldHtml(): string
    {
        $input = Html::tag('div', Cp::textHtml([
            'id' => 'fieldLimit',
            'name' => 'fieldLimit',
            'value' => $this->charLimit ?? $this->byteLimit,
            'type' => 'number',
            'min' => 1,
            'size' => 5,
        ]) . Cp::selectHtml([
            'id' => 'limitUnit',
            'name' => 'limitUnit',
            'options' => array_map(
                fn(string $value, string $label) => ['label' => $label, 'value' => $value],
                array_keys(self::limitUnits()),
                array_values(self::limitUnits()),
            ),
            'value' => $this->byteLimit ? self::LIMIT_UNIT_BYTES : self::LIMIT_UNIT_CHARS,
        ]), ['class' => 'flex']);

        return Cp::fieldHtml($input, [
            'label' => Craft::t('wahlberg', 'Field Limit'),
            'instructions' => Craft::t('wahlberg', 'The most characters or bytes of Markdown the field will accept. Counts the source an author types, not the HTML it renders to.'),
            'id' => 'fieldLimit',
            'errors' => $this->getErrors($this->byteLimit ? 'byteLimit' : 'charLimit'),
        ]);
    }
}
