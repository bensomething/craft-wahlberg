<?php

namespace bensomething\wahlberg\tests\unit;

use bensomething\wahlberg\fields\MarkdownField;
use bensomething\wahlberg\models\MarkdownData;
use bensomething\wahlberg\tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use yii\db\Schema;

class MarkdownFieldTest extends TestCase
{
    /** The settings this field adds, as opposed to the ones every field has. */
    private const SETTINGS = [
        'flavor', 'fontSize', 'minRows', 'maxRows',
        'showToolbar', 'showPreview', 'purifyHtml', 'purifierConfig',
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
        $value = $this->field(['flavor' => 'original', 'purifyHtml' => false])->normalizeValue('hi');

        self::assertSame('original', $value->getFlavor());
        self::assertFalse($value->getPurified());
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
            'unknown flavor' => [['flavor' => 'markdown-but-worse'], 'flavor'],
            'text too small' => [['fontSize' => 4], 'fontSize'],
            'text too big' => [['fontSize' => 64], 'fontSize'],
            'too few rows' => [['minRows' => 1], 'minRows'],
            'max below min' => [['minRows' => 12, 'maxRows' => 6], 'maxRows'],
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
            'every flavor' => [['flavor' => 'extra']],
            'smallest text' => [['fontSize' => MarkdownField::MIN_FONT_SIZE]],
            'largest text' => [['fontSize' => MarkdownField::MAX_FONT_SIZE]],
            'no maximum' => [['minRows' => 4, 'maxRows' => null]],
            'max equal to min' => [['minRows' => 12, 'maxRows' => 12]],
        ];
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

    public function testEveryFlavorIsOneTheParserKnows(): void
    {
        foreach (array_keys(MarkdownField::flavors()) as $flavor) {
            self::assertArrayHasKey($flavor, \yii\helpers\Markdown::$flavors, "Unknown flavor `$flavor`");
        }
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
