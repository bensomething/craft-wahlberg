<?php

namespace bensomething\wahlberg\tests\unit;

use bensomething\wahlberg\events\RegisterSnippetsEvent;
use bensomething\wahlberg\helpers\Snippets;
use bensomething\wahlberg\models\MarkdownData;
use bensomething\wahlberg\tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use yii\base\Event;

class SnippetsTest extends TestCase
{
    #[TestDox('no config file means no snippets, rather than an error')]
    public function testNoConfig(): void
    {
        self::assertSame([], Snippets::all());
        self::assertSame([], Snippets::options());
        self::assertSame([], Snippets::only('*'));
    }

    #[TestDox('a snippet can be a label and a body')]
    public function testLongForm(): void
    {
        $this->writePluginConfig(['snippets' => [
            'callout' => ['label' => 'Callout', 'body' => "> **Note**\n> \$0\n"],
        ]]);

        self::assertSame([
            'callout' => ['label' => 'Callout', 'body' => "> **Note**\n> \$0\n", 'icon' => null],
        ], Snippets::all());
    }

    #[TestDox('a snippet can name an icon for its menu item')]
    public function testIcon(): void
    {
        $this->writePluginConfig(['snippets' => [
            'callout' => ['label' => 'Callout', 'body' => 'body', 'icon' => 'circle-info'],
            'plain' => ['label' => 'Plain', 'body' => 'body'],
            'blank' => ['label' => 'Blank', 'body' => 'body', 'icon' => ''],
        ]]);

        $snippets = Snippets::all();

        self::assertSame('circle-info', $snippets['callout']['icon']);

        // Null rather than absent, so a menu item passes it straight through
        self::assertNull($snippets['plain']['icon']);
        self::assertNull($snippets['blank']['icon']);
    }

    #[TestDox('a snippet can be a body on its own, labelled from its key')]
    #[DataProvider('handles')]
    public function testShortForm(string $handle, string $label): void
    {
        $this->writePluginConfig(['snippets' => [$handle => 'body']]);

        self::assertSame($label, Snippets::all()[$handle]['label']);
    }

    public static function handles(): array
    {
        return [
            'one word' => ['callout', 'Callout'],
            'camelCase' => ['figureCaption', 'Figure Caption'],
            'kebab-case' => ['figure-caption', 'Figure Caption'],
            'snake_case' => ['figure_caption', 'Figure Caption'],
        ];
    }

    #[TestDox('anything without a body to insert is dropped')]
    public function testDropsEmpty(): void
    {
        $this->writePluginConfig(['snippets' => [
            'good' => 'body',
            'empty' => '',
            'bodyless' => ['label' => 'No body here'],
            'wrongType' => ['body' => ['not', 'a', 'string']],
        ]]);

        self::assertSame(['good'], array_keys(Snippets::all()));
    }

    #[TestDox('a malformed snippets key is ignored rather than fatal')]
    public function testMalformed(): void
    {
        $this->writePluginConfig(['snippets' => 'not an array']);
        self::assertSame([], Snippets::all());

        // A list has no handles to key by
        $this->writePluginConfig(['snippets' => ['body one', 'body two']]);
        self::assertSame([], Snippets::all());
    }

    #[TestDox('a field takes the ones it was given, in the order they were defined')]
    public function testOnly(): void
    {
        $this->writePluginConfig(['snippets' => [
            'first' => 'one',
            'second' => 'two',
            'third' => 'three',
        ]]);

        // Asked for backwards, comes back in config order
        self::assertSame(['first', 'third'], array_keys(Snippets::only(['third', 'first'])));

        // Never saved against one? All of them, so config additions reach it
        self::assertSame(['first', 'second', 'third'], array_keys(Snippets::only('*')));

        self::assertSame([], Snippets::only([]));

        // One that's been removed from the config since the field was saved
        self::assertSame(['first'], array_keys(Snippets::only(['first', 'gone'])));
    }

    #[TestDox('a plugin can register snippets of its own')]
    public function testPluginsCanRegister(): void
    {
        $this->onRegisterSnippets(function(RegisterSnippetsEvent $event) {
            $event->snippets['fromPlugin'] = ['label' => 'From a plugin', 'body' => 'plugin body'];
            // The shorthand works out here too
            $event->snippets['shorthand'] = 'shorthand body';
        });

        $this->writePluginConfig(['snippets' => ['fromConfig' => 'config body']]);

        self::assertSame(
            ['fromPlugin', 'shorthand', 'fromConfig'],
            array_keys(Snippets::all()),
        );

        self::assertSame('Shorthand', Snippets::all()['shorthand']['label']);
    }

    #[TestDox('an installation’s own config beats a plugin claiming the same handle')]
    public function testConfigWinsOverPlugins(): void
    {
        $this->onRegisterSnippets(function(RegisterSnippetsEvent $event) {
            $event->snippets['callout'] = ['label' => 'Plugin callout', 'body' => 'plugin body'];
        });

        $this->writePluginConfig(['snippets' => [
            'callout' => ['label' => 'Our callout', 'body' => 'our body'],
        ]]);

        self::assertSame(
            ['label' => 'Our callout', 'body' => 'our body', 'icon' => null],
            Snippets::all()['callout'],
        );
    }

    /**
     * Registered for this test only — Yii keeps class-level handlers for the life
     * of the process.
     */
    private function onRegisterSnippets(callable $handler): void
    {
        Event::on(Snippets::class, Snippets::EVENT_REGISTER_SNIPPETS, $handler);

        $this->registeredHandlers[] = $handler;
    }

    /** @var list<callable> */
    private array $registeredHandlers = [];

    protected function tearDown(): void
    {
        foreach ($this->registeredHandlers as $handler) {
            Event::off(Snippets::class, Snippets::EVENT_REGISTER_SNIPPETS, $handler);
        }

        $this->registeredHandlers = [];

        parent::tearDown();
    }

    #[TestDox('the markers are the ones the config file documents')]
    public function testMarkers(): void
    {
        self::assertSame('$0', Snippets::CARET);
        self::assertSame('$SELECTION', Snippets::SELECTION);
    }

    #[TestDox('the config template the plugin ships parses, and every snippet in it is usable')]
    public function testShippedTemplate(): void
    {
        $config = require dirname(__DIR__, 2) . '/src/config.php';

        self::assertArrayHasKey('snippets', $config);

        $this->writePluginConfig($config);
        $snippets = Snippets::all();

        // Nothing in it gets dropped on the way through
        self::assertSame(array_keys($config['snippets']), array_keys($snippets));

        foreach ($snippets as $handle => $snippet) {
            self::assertNotSame('', $snippet['label'], "`$handle` has no label");

            // The markers must survive PHP's double-quoted interpolation, where an
            // unescaped `${` is a variable rather than a stop. Every one that's got
            // this far therefore has to be a stop the editor can read: a digit, and
            // a default that closes
            preg_match_all('/\$\{[^}]*\}?/', $snippet['body'], $matches);

            foreach ($matches[0] as $marker) {
                self::assertMatchesRegularExpression(
                    '/^\$\{[0-9](:[^}]*)?\}$/',
                    $marker,
                    "`$handle` has a marker the editor won't read: $marker",
                );
            }
        }

        // Between them they demonstrate the markers worth demonstrating
        $bodies = implode('', array_column($snippets, 'body'));
        self::assertStringContainsString(Snippets::CARET, $bodies);
        self::assertStringContainsString(Snippets::SELECTION, $bodies);

        // Including a stop carrying a default, since that's the one whose escaping
        // is easiest to get wrong
        self::assertMatchesRegularExpression('/\$\{[0-9]:[^}]+\}/', $bodies);
    }

    #[TestDox('every snippet in the shipped template still renders once the markers are out and the purifier has been through')]
    public function testShippedTemplateSurvivesPurification(): void
    {
        $config = require dirname(__DIR__, 2) . '/src/config.php';

        $this->writePluginConfig($config);

        foreach (Snippets::all() as $handle => $snippet) {
            // What an author is left holding, with nothing selected: the selection
            // first, so a stop defaulting to it keeps what it stood in for, then the
            // stops themselves — defaults kept, bare ones stood in for
            $body = preg_replace(
                ['/\$\{[0-9]:([^}]*)\}/', '/\$\{[0-9]\}/', '/\$[0-9]/'],
                ['$1', 'Something', 'Something'],
                str_replace(Snippets::SELECTION, 'Selected text', $snippet['body']),
            );

            // Purified, as a field ships. An example that comes out empty is one
            // that does nothing on a stock install. Refs off: they want a database
            $html = trim((string)(new MarkdownData($body, 'gfm-comment', true, null, false))->getHtml());

            self::assertNotSame('', $html, "`$handle` renders to nothing");

            // Nor survive as bare text of the markup it meant to emit: `<details>`
            // is stripped wholesale and takes its meaning with it
            self::assertMatchesRegularExpression(
                '/<[a-z]/i',
                $html,
                "`$handle` renders no tags, so whatever markup it emits is being stripped",
            );
        }
    }
}
