<?php

namespace bensomething\wahlberg;

use bensomething\wahlberg\fields\MarkdownField;
use bensomething\wahlberg\web\twig\Extension;
use Craft;
use craft\events\RegisterComponentTypesEvent;
use craft\services\Fields;
use yii\base\Event;

class Plugin extends \craft\base\Plugin
{
    public string $schemaVersion = '1.0.0';

    public function init(): void
    {
        parent::init();

        Event::on(
            Fields::class,
            Fields::EVENT_REGISTER_FIELD_TYPES,
            function(RegisterComponentTypesEvent $event) {
                $event->types[] = MarkdownField::class;
            }
        );

        // Powers the `wahlberg/editor` Twig macros
        Craft::$app->getView()->registerTwigExtension(new Extension());
    }
}
