<?php

namespace bensomething\wahlberg\events;

use yii\base\Event;

/**
 * Raised so plugins can add snippets of their own to the ones an installation
 * defines in `config/wahlberg.php`.
 *
 * ```php
 * use bensomething\wahlberg\events\RegisterSnippetsEvent;
 * use bensomething\wahlberg\helpers\Snippets;
 * use yii\base\Event;
 *
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
     * @var array<string, array{label: string, body: string}|string> The snippets,
     * keyed by handle. Already carries whatever `config/wahlberg.php` defined.
     *
     * A handle already in here belongs to the installation’s own config, which
     * takes precedence: overwrite it and you’re overruling a decision someone made
     * about their own site.
     */
    public array $snippets = [];
}
