<?php

namespace bensomething\wahlberg\tests\unit;

use bensomething\wahlberg\helpers\Purifier;
use bensomething\wahlberg\tests\TestCase;
use PHPUnit\Framework\Attributes\TestDox;

class PurifierTest extends TestCase
{
    public function testStripsScripts(): void
    {
        self::assertStringNotContainsString('<script', Purifier::process('<p>hi</p><script>alert(1)</script>'));
    }

    #[TestDox('the stock config allows YouTube and Vimeo embeds through')]
    public function testDefaultsAllowVideoEmbeds(): void
    {
        $youtube = Purifier::process('<iframe src="https://www.youtube.com/embed/abc"></iframe>');
        $vimeo = Purifier::process('<iframe src="https://player.vimeo.com/video/123"></iframe>');
        $elsewhere = Purifier::process('<iframe src="https://example.com/nope"></iframe>');

        self::assertStringContainsString('youtube.com/embed/abc', $youtube);
        self::assertStringContainsString('player.vimeo.com/video/123', $vimeo);
        self::assertStringNotContainsString('example.com/nope', $elsewhere);
    }

    #[TestDox('code blocks keep their language class, so highlighters still work')]
    public function testKeepsCodeLanguageClasses(): void
    {
        $html = Purifier::process('<pre><code class="language-php">echo 1;</code></pre>');

        self::assertStringContainsString('language-php', $html);
    }

    #[TestDox('a named config in config/htmlpurifier/ is used')]
    public function testNamedConfigIsLoaded(): void
    {
        $this->writePurifierConfig('Strict.json', ['HTML.Allowed' => 'p']);

        $html = Purifier::process('<p>kept</p><em>dropped</em>', 'Strict.json');

        self::assertStringContainsString('<p>kept</p>', $html);
        self::assertStringNotContainsString('<em>', $html);
    }

    #[TestDox('Default.json applies when no config is named')]
    public function testDefaultConfigIsPickedUpAutomatically(): void
    {
        $this->writePurifierConfig('Default.json', ['HTML.Allowed' => 'p']);

        self::assertStringNotContainsString('<em>', Purifier::process('<p>kept</p><em>dropped</em>'));
    }

    #[TestDox('a config that has gone missing falls back rather than blowing up')]
    public function testMissingConfigFallsBack(): void
    {
        $html = Purifier::process('<iframe src="https://www.youtube.com/embed/abc"></iframe>', 'Gone.json');

        // Fell all the way back to our defaults, which allow the embed
        self::assertStringContainsString('youtube.com/embed/abc', $html);
    }

    #[TestDox('a missing named config falls back to Default.json when there is one')]
    public function testMissingConfigFallsBackToDefaultFile(): void
    {
        $this->writePurifierConfig('Default.json', ['HTML.Allowed' => 'p']);

        self::assertStringNotContainsString('<em>', Purifier::process('<p>kept</p><em>dropped</em>', 'Gone.json'));
    }

    #[TestDox('the settings dropdown lists the config files, Default aside')]
    public function testConfigOptions(): void
    {
        $this->writePurifierConfig('Default.json', []);
        $this->writePurifierConfig('Strict.json', []);
        $this->writePurifierConfig('Loose.json', []);

        $options = Purifier::configOptions();

        self::assertSame('Default', $options['']);
        self::assertArrayHasKey('Strict.json', $options);
        self::assertArrayHasKey('Loose.json', $options);
        self::assertArrayNotHasKey('Default.json', $options);
    }

    public function testConfigOptionsSurvivesAMissingDirectory(): void
    {
        \bensomething\wahlberg\tests\support\Dir::remove($this->purifierConfigPath);

        self::assertSame(['' => 'Default'], Purifier::configOptions());
    }
}
