<?php

declare(strict_types=1);

namespace bensomething\wahlberg\Markdown\Alerts;

use League\CommonMark\Node\Block\AbstractBlock;

/** A quote that opened with `[!NOTE]` or one of the others, holding what followed. */
final class Alert extends AbstractBlock
{
    public const array TYPES = ['note', 'tip', 'important', 'warning', 'caution'];

    /** @param  value-of<self::TYPES>  $type */
    public function __construct(
        public readonly string $type,
    ) {
        parent::__construct();
    }
}
