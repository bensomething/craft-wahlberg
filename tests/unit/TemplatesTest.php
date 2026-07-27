<?php

namespace bensomething\wahlberg\tests\unit;

use bensomething\wahlberg\tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Throwable;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * That the templates parse.
 *
 * Not that they render: that wants Craft’s view, which the fake application
 * hasn’t got. Twig only reports a syntax error when it compiles a template, and
 * nothing else in this suite ever does, so without this a stray `{% endif %}`
 * would ship and only turn up in a browser.
 *
 * Craft’s own filters and functions are stubbed to whatever keeps the parser
 * moving — the point is the shape of the template, not what it produces.
 */
class TemplatesTest extends TestCase
{
    private const PATH = __DIR__ . '/../../src/templates';

    public static function templates(): array
    {
        return array_map(
            fn(string $file) => [basename($file)],
            glob(self::PATH . '/*.twig') ?: [],
        );
    }

    #[DataProvider('templates')]
    #[TestDox('$file parses')]
    public function testParses(string $file): void
    {
        $twig = new Environment(new FilesystemLoader(self::PATH));

        $twig->addFilter(new TwigFilter('t', fn($string) => $string));
        $twig->addFilter(new TwigFilter('md', fn($string) => $string));
        $twig->addFilter(new TwigFilter('raw', fn($string) => $string));
        $twig->addFunction(new TwigFunction('attr', fn() => ''));
        $twig->addFunction(new TwigFunction('wahlbergEditor', fn() => ''));
        $twig->addFunction(new TwigFunction('tag', fn() => ''));

        try {
            $source = $twig->getLoader()->getSourceContext($file);
            $twig->parse($twig->tokenize($source));
        } catch (Throwable $e) {
            self::fail("$file doesn't parse: {$e->getMessage()}");
        }

        self::assertTrue(true);
    }

    #[TestDox('there are templates to check, so this can’t pass by finding none')]
    public function testThereAreTemplates(): void
    {
        self::assertNotSame([], self::templates());
    }
}
