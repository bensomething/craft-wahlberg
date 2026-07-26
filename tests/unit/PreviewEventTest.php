<?php

namespace bensomething\wahlberg\tests\unit;

use bensomething\wahlberg\controllers\PreviewController;
use bensomething\wahlberg\events\ModifyPreviewEvent;
use bensomething\wahlberg\helpers\ReferenceTags;
use bensomething\wahlberg\tests\TestCase;
use Craft;
use PHPUnit\Framework\Attributes\TestDox;
use ReflectionMethod;
use yii\base\Event;

/**
 * The Preview tab’s escape hatch, for markup HTML Purifier would take back out.
 *
 * The controller action itself wants a web request and a session, neither of which
 * the fake application has, so these go at the one method that does the work.
 */
class PreviewEventTest extends TestCase
{
    /** @var list<callable> */
    private array $handlers = [];

    protected function tearDown(): void
    {
        foreach ($this->handlers as $handler) {
            Event::off(PreviewController::class, PreviewController::EVENT_MODIFY_PREVIEW, $handler);
        }

        $this->handlers = [];

        parent::tearDown();
    }

    private function on(callable $handler): void
    {
        Event::on(PreviewController::class, PreviewController::EVENT_MODIFY_PREVIEW, $handler);

        $this->handlers[] = $handler;
    }

    private function modify(string $html): string
    {
        $method = new ReflectionMethod(PreviewController::class, 'modified');

        return $method->invoke(new PreviewController('wahlberg-preview', Craft::$app), $html);
    }

    #[TestDox('with nothing listening the HTML goes back exactly as it came in')]
    public function testNoListeners(): void
    {
        $html = '<p>Hello {icon:star}</p>';

        self::assertSame($html, $this->modify($html));
    }

    #[TestDox('a listener can replace what the preview shows')]
    public function testListenerCanModify(): void
    {
        $this->on(function(ModifyPreviewEvent $event) {
            $event->html = str_replace('{icon:star}', '<svg data-icon="star"></svg>', $event->html);
        });

        self::assertSame(
            '<p>Hello <svg data-icon="star"></svg></p>',
            $this->modify('<p>Hello {icon:star}</p>'),
        );
    }

    #[TestDox('a listener runs after purification, which is the whole point of it')]
    public function testRunsAfterPurification(): void
    {
        // Inline SVG is the case this exists for: added before the purifier it comes
        // straight back off, so a listener has to be able to add it afterwards
        $this->on(function(ModifyPreviewEvent $event) {
            $event->html .= '<svg></svg>';
        });

        self::assertStringContainsString('<svg></svg>', $this->modify('<p>Hi</p>'));
    }

    #[TestDox('a listener can leave code alone the way reference tags do')]
    public function testListenerCanSkipCode(): void
    {
        $this->on(function(ModifyPreviewEvent $event) {
            $event->html = ReferenceTags::outsideCode(
                $event->html,
                static fn(string $segment): string => str_replace('{icon:star}', 'ICON', $segment),
            );
        });

        // The token in the code span is an author documenting the syntax, and stays
        self::assertSame(
            '<p>ICON and <code>{icon:star}</code></p>',
            $this->modify('<p>{icon:star} and <code>{icon:star}</code></p>'),
        );
    }
}
