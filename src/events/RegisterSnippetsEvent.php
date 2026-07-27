<?php

namespace bensomething\wahlberg\events;

use yii\base\Event;

/**
 * Raised so plugins can add snippets to the ones an installation defines in
 * `config/wahlberg.php`.
 *
 * ```php
 * Event::on(
 *     Snippets::class,
 *     Snippets::EVENT_REGISTER_SNIPPETS,
 *     function(RegisterSnippetsEvent $event) {
 *         $event->snippets['productSpec'] = [
 *             'label' => Craft::t('my-plugin', 'Product spec'),
 *             'body' => "{spec:\$0}\n",
 *         ];
 *     }
 * );
 * ```
 */
class RegisterSnippetsEvent extends Event
{
    /**
     * @var array<string, array{label: string, body: string}|string> Keyed by handle,
     * already carrying whatever the config file defined. A handle in here is the
     * installation’s own and takes precedence — overwriting it overrules a decision
     * someone made about their site.
     */
    public array $snippets = [];
}
