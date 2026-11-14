<?php

declare(strict_types=1);

namespace bensomething\wahlberg\Events;

/**
 * Dispatched once per request, before the config file's snippets are read, so a
 * plugin or module can add snippets of its own. They take the config file's format,
 * keyed by handle. A handle the config file also defines goes to the config file.
 */
class SnippetsRegistering
{
    /** @param array<string, mixed> $snippets */
    public function __construct(
        public array $snippets = [],
    ) {}
}
