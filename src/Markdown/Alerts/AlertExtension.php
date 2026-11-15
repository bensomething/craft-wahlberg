<?php

declare(strict_types=1);

namespace bensomething\wahlberg\Markdown\Alerts;

use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\ExtensionInterface;

/**
 * GitHub's alerts. league/commonmark doesn't have them, so quotes are parsed as
 * usual and the ones that open with a marker are turned into alerts afterwards.
 */
final class AlertExtension implements ExtensionInterface
{
    public function register(EnvironmentBuilderInterface $environment): void
    {
        $environment
            ->addEventListener(DocumentParsedEvent::class, new AlertProcessor)
            ->addRenderer(Alert::class, new AlertRenderer);
    }
}
