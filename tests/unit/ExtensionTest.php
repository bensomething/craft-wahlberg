<?php

namespace bensomething\wahlberg\tests\unit;

use bensomething\wahlberg\models\MarkdownData;
use bensomething\wahlberg\tests\TestCase;
use bensomething\wahlberg\web\twig\Extension;
use PHPUnit\Framework\Attributes\TestDox;
use Twig\Markup;

class ExtensionTest extends TestCase
{
    private Extension $extension;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension = new Extension();
    }

    public function testMarkyParsesMarkdown(): void
    {
        $html = $this->extension->marky('# Heading');

        self::assertInstanceOf(Markup::class, $html);
        self::assertStringContainsString('<h1>Heading</h1>', (string)$html);
    }

    #[TestDox('|marky purifies by default, where |md doesn’t')]
    public function testMarkyPurifiesByDefault(): void
    {
        self::assertStringNotContainsString('<script', (string)$this->extension->marky('<script>alert(1)</script>'));
        self::assertStringContainsString('<script', (string)$this->extension->marky('<script>alert(1)</script>', purify: false));
    }

    public function testMarkyHonoursTheFlavour(): void
    {
        self::assertStringContainsString('<br', (string)$this->extension->marky("one\ntwo", flavour: 'gfm-comment'));
        self::assertStringNotContainsString('<br', (string)$this->extension->marky("one\ntwo", flavour: 'gfm'));
    }

    #[TestDox('|marky defaults to the same flavour a field does, line breaks and all')]
    public function testMarkyDefaultsToPreservedLineBreaks(): void
    {
        self::assertStringContainsString('<br', (string)$this->extension->marky("one\ntwo"));
    }

    #[TestDox('|marky on an empty value gives back nothing, not an empty paragraph')]
    public function testMarkyHandlesEmptyValues(): void
    {
        self::assertSame('', (string)$this->extension->marky(''));
        self::assertSame('', (string)$this->extension->marky('   '));
        self::assertSame('', (string)$this->extension->marky(null));
    }

    #[TestDox('|marky on a field value reuses that field’s own parse')]
    public function testMarkyReusesAFieldValuesParse(): void
    {
        $data = new MarkdownData('# Heading', 'gfm', true);

        // Delegated to the value object rather than re-parsed, so the field's settings
        // are honoured whatever they were — including the ones with no getter to copy
        self::assertSame((string)$data->getHtml(), (string)$this->extension->marky($data));
    }

    #[TestDox('|marky arguments override a field value’s settings one at a time')]
    public function testMarkyOverridesAFieldValuesSettings(): void
    {
        $data = new MarkdownData("one\ntwo", 'gfm', purify: false, parseRefs: false);
        $html = (string)$this->extension->marky($data, flavour: 'gfm-comment');

        // The overridden setting takes, and the ones left alone carry over
        self::assertStringContainsString('<br', $html);
        self::assertFalse($data->getParseRefs());
    }

    public function testEditorFunctionIsRegistered(): void
    {
        $names = array_map(static fn($f) => $f->getName(), $this->extension->getFunctions());
        $filters = array_map(static fn($f) => $f->getName(), $this->extension->getFilters());

        self::assertContains('wahlbergEditor', $names);
        self::assertContains('marky', $filters);
    }
}
