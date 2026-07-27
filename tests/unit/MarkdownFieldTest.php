<?php

namespace bensomething\wahlberg\tests\unit;

use bensomething\wahlberg\Editor;
use bensomething\wahlberg\fields\MarkdownField;
use bensomething\wahlberg\models\MarkdownData;
use bensomething\wahlberg\tests\TestCase;
use craft\elements\Entry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use yii\db\Schema;

class MarkdownFieldTest extends TestCase
{
    /** The settings this field adds, as opposed to the ones every field has. */
    private const SETTINGS = [
        'flavour', 'preserveLineBreaks', 'inlineOnly', 'encodeHtml',
        'fontSize', 'minRows', 'maxRows', 'placeholder',
        'showToolbar', 'toolbarButtons', 'showPreview', 'showHighlighting', 'showStats',
        'charLimit', 'byteLimit',
        'parseRefs', 'purifyHtml', 'purifierConfig',
        'availableVolumes', 'showUnpermittedVolumes', 'showUnpermittedFiles',
    ];

    private function field(array $config = []): MarkdownField
    {
        return new MarkdownField($config + ['handle' => 'body']);
    }

    public function testStoresTextAndReturnsAMarkdownDataObject(): void
    {
        self::assertSame(Schema::TYPE_TEXT, MarkdownField::dbType());
        self::assertSame('\\' . MarkdownData::class . '|null', MarkdownField::phpType());
    }

    public function testNormalizesStringsIntoMarkdownData(): void
    {
        $value = $this->field()->normalizeValue("# Heading\n");

        self::assertInstanceOf(MarkdownData::class, $value);
        self::assertSame("# Heading\n", $value->getRaw());
    }

    #[TestDox('an empty or blank value normalizes to null, so required validation catches it')]
    #[DataProvider('blankValues')]
    public function testBlankValuesNormalizeToNull(mixed $value): void
    {
        self::assertNull($this->field()->normalizeValue($value));
    }

    public static function blankValues(): array
    {
        return [
            'null' => [null],
            'empty string' => [''],
            'spaces' => ['   '],
            'newlines' => ["\n\n"],
            'not a string' => [42],
        ];
    }

    public function testNormalizingIsIdempotent(): void
    {
        $field = $this->field();
        $value = $field->normalizeValue('# Heading');

        self::assertSame($value, $field->normalizeValue($value));
    }

    #[TestDox('the field settings reach the value object')]
    public function testSettingsArePassedToTheValue(): void
    {
        $value = $this->field([
            'flavour' => 'original',
            'purifyHtml' => false,
            'purifierConfig' => 'Loose.json',
            'parseRefs' => false,
        ])->normalizeValue('hi');

        self::assertSame('original', $value->getFlavour());
        self::assertFalse($value->getPurified());
        self::assertSame('Loose.json', $value->getPurifierConfig());
        self::assertFalse($value->getParseRefs());
    }

    #[TestDox('preserved line breaks resolve to the `gfm-comment` parser')]
    #[DataProvider('lineBreakSettings')]
    public function testPreserveLineBreaksResolvesToAParserFlavour(array $config, string $expected): void
    {
        self::assertSame($expected, $this->field($config)->getParserFlavour());
        self::assertSame($expected, $this->field($config)->normalizeValue('hi')->getFlavour());
    }

    public static function lineBreakSettings(): array
    {
        return [
            'on by default' => [[], 'gfm-comment'],
            'off' => [['preserveLineBreaks' => false], 'gfm'],
            'on' => [['preserveLineBreaks' => true], 'gfm-comment'],
            // The other parsers have no such option, so the setting can’t leak into them
            'ignored by traditional' => [['flavour' => 'original', 'preserveLineBreaks' => true], 'original'],
            'ignored by extra' => [['flavour' => 'extra', 'preserveLineBreaks' => true], 'extra'],
        ];
    }

    #[TestDox('the setting decides whether a single newline becomes a `<br>`')]
    public function testPreserveLineBreaksChangesTheHtml(): void
    {
        self::assertStringContainsString('<br', (string)$this->field()->normalizeValue("one\ntwo")->getHtml());
        self::assertStringNotContainsString(
            '<br',
            (string)$this->field(['preserveLineBreaks' => false])->normalizeValue("one\ntwo")->getHtml(),
        );
    }

    #[TestDox('`flavor` from before the British spelling still applies')]
    public function testLegacyFlavorSpellingIsCarriedOver(): void
    {
        $field = $this->field(['flavor' => MarkdownField::FLAVOUR_ORIGINAL]);

        self::assertSame(MarkdownField::FLAVOUR_ORIGINAL, $field->flavour);
        self::assertArrayNotHasKey('flavor', $field->getSettings());

        // The old spelling still routes through the `gfm-comment` migration
        self::assertTrue($this->field(['flavor' => 'gfm-comment'])->preserveLineBreaks);
    }

    #[TestDox('`gfm-comment` from before it became a setting still applies')]
    public function testLegacyGfmCommentFlavourIsCarriedOver(): void
    {
        $field = $this->field(['flavour' => MarkdownField::FLAVOUR_GFM_COMMENT]);

        self::assertSame(MarkdownField::FLAVOUR_GFM, $field->flavour);
        self::assertTrue($field->preserveLineBreaks);
        self::assertSame(MarkdownField::FLAVOUR_GFM_COMMENT, $field->getParserFlavour());

        // An explicit setting alongside the old flavour name is left alone
        self::assertFalse(
            $this->field(['flavour' => MarkdownField::FLAVOUR_GFM_COMMENT, 'preserveLineBreaks' => false])
                ->preserveLineBreaks,
        );
    }

    #[TestDox('reference tags resolve against the element’s own site')]
    public function testTheElementsSiteIsPassedToTheValue(): void
    {
        $element = new Entry();
        $element->siteId = 3;

        self::assertSame(3, $this->field()->normalizeValue('hi', $element)->getSiteId());

        // Nothing to go on outside an element — Craft falls back to the current site
        self::assertNull($this->field()->normalizeValue('hi')->getSiteId());
    }

    public function testSerializesBackToTheRawSource(): void
    {
        $field = $this->field();
        $markdown = "# Heading\n\n- one\n";

        self::assertSame($markdown, $field->serializeValue($field->normalizeValue($markdown)));
        self::assertNull($field->serializeValue(null));
        self::assertNull($field->serializeValue('not a MarkdownData'));
    }

    #[TestDox('`initialRows` from before the rename still applies')]
    public function testLegacyInitialRowsSettingIsCarriedOver(): void
    {
        $field = $this->field(['initialRows' => 8]);

        self::assertSame(8, $field->minRows);
        self::assertArrayNotHasKey('initialRows', $field->getSettings());
    }

    #[TestDox('an explicit minRows wins over the legacy setting')]
    public function testExplicitMinRowsBeatsLegacySetting(): void
    {
        self::assertSame(20, $this->field(['initialRows' => 8, 'minRows' => 20])->minRows);
    }

    #[DataProvider('invalidSettings')]
    public function testRejectsNonsenseSettings(array $config, string $attribute): void
    {
        $field = $this->field($config);

        // Just this attribute: Craft's own field rules include a uniqueness check on
        // the handle, and that one wants a database
        $field->validate([$attribute]);

        self::assertTrue($field->hasErrors($attribute), "Expected an error on `$attribute`");
    }

    public static function invalidSettings(): array
    {
        return [
            'unknown flavour' => [['flavour' => 'markdown-but-worse'], 'flavour'],
            'text too small' => [['fontSize' => 4], 'fontSize'],
            'text too big' => [['fontSize' => 64], 'fontSize'],
            'too few rows' => [['minRows' => MarkdownField::MIN_ROWS - 1], 'minRows'],
            'negative rows' => [['minRows' => -1], 'minRows'],
            'too few maximum rows' => [['minRows' => MarkdownField::MIN_ROWS, 'maxRows' => MarkdownField::MIN_ROWS - 1], 'maxRows'],
            'max below min' => [['minRows' => 12, 'maxRows' => 6], 'maxRows'],
            'limit of nothing' => [['charLimit' => 0], 'charLimit'],
            'negative limit' => [['byteLimit' => -1], 'byteLimit'],
            'unknown toolbar button' => [['toolbarButtons' => ['bold', 'blink']], 'toolbarButtons'],
        ];
    }

    #[DataProvider('validSettings')]
    public function testAcceptsSensibleSettings(array $config): void
    {
        $field = $this->field($config);
        $field->validate(self::SETTINGS);

        self::assertSame([], $field->getErrors());
    }

    public static function validSettings(): array
    {
        return [
            'defaults' => [[]],
            'every flavour' => [['flavour' => 'extra']],
            'smallest text' => [['fontSize' => MarkdownField::MIN_FONT_SIZE]],
            'largest text' => [['fontSize' => MarkdownField::MAX_FONT_SIZE]],
            'no maximum' => [['minRows' => 4, 'maxRows' => null]],
            'max equal to min' => [['minRows' => 12, 'maxRows' => 12]],
            'as short as it goes' => [['minRows' => MarkdownField::MIN_ROWS]],
            'as short as it goes, fixed' => [['minRows' => MarkdownField::MIN_ROWS, 'maxRows' => MarkdownField::MIN_ROWS]],
            'no limit' => [['charLimit' => null, 'byteLimit' => null]],
            'a limit in characters' => [['charLimit' => 500]],
            'a limit in bytes' => [['byteLimit' => 500]],
            'no toolbar buttons' => [['toolbarButtons' => []]],
            'every toolbar button' => [['toolbarButtons' => array_keys(Editor::commands())]],
            'all volumes' => [['availableVolumes' => '*']],
        ];
    }

    #[TestDox('a new field starts out with the shared defaults')]
    public function testDefaults(): void
    {
        $field = $this->field();

        // The standalone editor falls back to these same constants, so a plugin
        // rendering the macros gets an editor that matches a Markdown field
        self::assertSame(14, MarkdownField::DEFAULT_FONT_SIZE);
        self::assertSame(2, MarkdownField::DEFAULT_MIN_ROWS);

        // A field starts out two rows tall, but can be set shorter than that
        self::assertGreaterThan(MarkdownField::MIN_ROWS, MarkdownField::DEFAULT_MIN_ROWS);

        self::assertSame(MarkdownField::DEFAULT_FONT_SIZE, $field->fontSize);
        self::assertSame(MarkdownField::DEFAULT_MIN_ROWS, $field->minRows);
        self::assertNull($field->maxRows);

        // The editor's chrome is all on unless a field turns it off
        self::assertTrue($field->showToolbar);
        self::assertTrue($field->showPreview);
        self::assertTrue($field->showHighlighting);

        // Except the counts, which are opt-in: most fields don't want them
        self::assertFalse($field->showStats);

        // And the parsing stays as it was before any of this was configurable
        self::assertFalse($field->inlineOnly);
        self::assertFalse($field->encodeHtml);
        self::assertNull($field->charLimit);
        self::assertNull($field->byteLimit);
        self::assertNull($field->placeholder);

        self::assertSame(MarkdownField::DEFAULT_TOOLBAR_BUTTONS, $field->toolbarButtons);
    }

    #[TestDox('every default toolbar button is a command the editor knows')]
    public function testDefaultToolbarButtonsAreReal(): void
    {
        $commands = array_keys(Editor::commands());

        foreach (MarkdownField::DEFAULT_TOOLBAR_BUTTONS as $button) {
            self::assertContains($button, $commands, "Unknown command `$button`");
        }
    }

    #[DataProvider('headingSelections')]
    #[TestDox('however many heading levels are on, the toolbar gets one control')]
    public function testHeadingControl(array $levels, ?string $command, bool $isMenu): void
    {
        $groups = Editor::toolbar([...$levels, 'bold']);
        $first = $groups[0][0] ?? null;

        if ($command === null && !$isMenu) {
            // No levels, so no heading control — `bold` is all that's left
            self::assertSame('bold', $first['command']);
            return;
        }

        if ($isMenu) {
            self::assertArrayNotHasKey('command', $first);

            // Every level ticked, and nothing that wasn't
            self::assertSame(
                array_map(fn(string $level) => (int)substr($level, 1), $levels),
                $first['headings'],
            );

            return;
        }

        self::assertSame($command, $first['command']);
        self::assertArrayNotHasKey('headings', $first);

        // The plain H whichever level, with the level in the label
        self::assertSame('heading', $first['iconName']);
        self::assertSame('Heading ' . substr($command, 1), $first['label']);
    }

    public static function headingSelections(): array
    {
        return [
            'none' => [[], null, false],
            'one' => [['h2'], 'h2', false],
            'one, deep' => [['h6'], 'h6', false],
            'two' => [['h2', 'h3'], null, true],
            'all six' => [['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], null, true],
        ];
    }

    #[TestDox('the toolbar keeps the order commands() declares, however many are off')]
    public function testToolbarKeepsItsOrder(): void
    {
        // Asked for backwards, out of two groups
        $groups = Editor::toolbar(['ol', 'italic', 'bold']);

        self::assertSame([['bold', 'italic'], ['ol']], array_map(
            fn(array $group) => array_column($group, 'command'),
            $groups,
        ));

        // A group nothing was picked from is dropped, divider and all
        self::assertSame([['bold']], array_map(
            fn(array $group) => array_column($group, 'command'),
            Editor::toolbar(['bold']),
        ));

        self::assertSame([], Editor::toolbar([]));
    }

    #[DataProvider('limitSettings')]
    #[TestDox('the settings screen posts one limit and its units, and the field splits them')]
    public function testLimitUnits(array $config, ?int $chars, ?int $bytes): void
    {
        $field = $this->field($config);

        self::assertSame($chars, $field->charLimit);
        self::assertSame($bytes, $field->byteLimit);
    }

    public static function limitSettings(): array
    {
        return [
            'characters' => [['fieldLimit' => '500', 'limitUnit' => 'chars'], 500, null],
            'bytes' => [['fieldLimit' => '500', 'limitUnit' => 'bytes'], null, 500],
            'units left off' => [['fieldLimit' => '500'], 500, null],
            'cleared' => [['fieldLimit' => '', 'limitUnit' => 'chars'], null, null],
            // Switching units clears the other, or a field carries both
            'switched to bytes' => [['charLimit' => 100, 'fieldLimit' => '500', 'limitUnit' => 'bytes'], null, 500],
            'switched to characters' => [['byteLimit' => 100, 'fieldLimit' => '500', 'limitUnit' => 'chars'], 500, null],
        ];
    }

    #[TestDox('a field over its limit fails validation, counted in the units it was set in')]
    public function testLimitValidation(): void
    {
        // Four bytes, one character — which is the point of having two units
        $chars = $this->field(['charLimit' => 1]);
        $chars->validateLength($element = $this->elementWith('🐟'));
        self::assertSame([], $element->getErrors());

        // `Element::addError()` strips the `field:` prefix validators use
        $bytes = $this->field(['byteLimit' => 1]);
        $bytes->validateLength($element = $this->elementWith('🐟'));
        self::assertNotSame([], $element->getErrors('body'));

        // Named the way Craft names it for a Plain Text field over its limit, and
        // not “the input value”, which is what a validator run outside a model says
        self::assertSame(
            'Body should contain at most 1 character.',
            $element->getFirstError('body'),
        );

        // Counted against the Markdown typed, not the longer HTML it renders to
        $field = $this->field(['charLimit' => 11]);
        $field->validateLength($element = $this->elementWith($field->normalizeValue('**bold** hi')));
        self::assertSame([], $element->getErrors());
    }

    /**
     * An element answering with a fixed value. `setFieldValue()` goes through
     * `CustomFieldBehavior`, which Craft generates from the database.
     */
    private function elementWith(mixed $value): Entry
    {
        return new class($value) extends Entry {
            public function __construct(private readonly mixed $value)
            {
                parent::__construct();
            }

            public function getFieldValue(string $fieldHandle): mixed
            {
                return $this->value;
            }
        };
    }

    #[TestDox('settings are labelled the way the settings screen labels them')]
    public function testAttributeLabels(): void
    {
        $labels = $this->field()->attributeLabels();

        self::assertSame('Text Size', $labels['fontSize']);
        self::assertSame('Minimum Rows', $labels['minRows']);
        self::assertSame('Maximum Rows', $labels['maxRows']);
    }

    #[TestDox('search indexes the words, not the syntax')]
    public function testSearchKeywords(): void
    {
        $field = $this->field();
        $element = new \craft\elements\Entry();

        $keywords = $field->getSearchKeywords($field->normalizeValue('# Heading with **bold**'), $element);

        self::assertSame('Heading with bold', $keywords);
        self::assertSame('', $field->getSearchKeywords(null, $element));
    }

    #[TestDox('the element index preview is plain text, truncated and escaped')]
    public function testPreviewHtml(): void
    {
        $field = $this->field();
        $element = new \craft\elements\Entry();

        self::assertSame('Fish &amp; chips', $field->getPreviewHtml($field->normalizeValue('Fish & chips'), $element));
        self::assertSame('', $field->getPreviewHtml(null, $element));

        $long = $field->getPreviewHtml($field->normalizeValue(str_repeat('word ', 50)), $element);
        self::assertLessThanOrEqual(100, strlen($long));
    }

    public function testEveryFlavourIsOneTheParserKnows(): void
    {
        foreach (MarkdownField::parserFlavours() as $flavour) {
            self::assertArrayHasKey($flavour, \yii\helpers\Markdown::$flavors, "Unknown flavour `$flavour`");
        }

        // Everything offered as a choice has to be parseable, but not the other way
        // round — `gfm-comment` is a resolved setting rather than one of the options
        self::assertSame([], array_diff(array_keys(MarkdownField::flavours()), MarkdownField::parserFlavours()));
        self::assertArrayNotHasKey(MarkdownField::FLAVOUR_GFM_COMMENT, MarkdownField::flavours());
    }

    #[TestDox('the field icon resolves to something that exists')]
    public function testIcon(): void
    {
        $icon = MarkdownField::icon();

        self::assertTrue(
            is_file($icon) || preg_match('/^[\da-z\-]+$/', $icon) === 1,
            "Icon is neither a file nor a system icon name: $icon",
        );
    }
}
