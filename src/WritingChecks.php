<?php

declare(strict_types=1);

namespace bensomething\wahlberg;

use bensomething\wahlberg\Markdown\WahlbergFlavor;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Field\Data\MarkdownData;
use CraftCms\Cms\Markdown\Markdown;
use CraftCms\Cms\Support\Facades\Sites;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\Node;
use League\CommonMark\Node\RawMarkupContainerInterface;
use League\CommonMark\Node\StringContainerHelper;

use function CraftCms\Cms\t;

/**
 * Checks on a Markdown field's content that a field layout shows as the field's
 * warning: things worth fixing that shouldn't stop a save. All are on until they're
 * unticked in Wahlberg's settings.
 *
 * Read from the parsed document, in the field's own flavour, so Markdown inside code
 * is never mistaken for an image, a heading or a link.
 */
class WritingChecks
{
    /** Images with no alt text. */
    public const string ALT_TEXT = 'altText';

    /** A level 1 heading, which the page's title usually is already. */
    public const string H1 = 'h1';

    /** A heading more than one level below the one before it. */
    public const string HEADING_LEVELS = 'headingLevels';

    /** Links to entries that are disabled, not published yet, expired or gone. */
    public const string ENTRY_LINKS = 'entryLinks';

    public const array ALL = [self::ALT_TEXT, self::H1, self::HEADING_LEVELS, self::ENTRY_LINKS];

    /**
     * The setting's value for every check, including any added in a later version,
     * as the settings screen's “All” checkbox saves it.
     */
    public const string EVERY = '*';

    /** An entry reference tag, by ID or UID, with or without a site. */
    private const string ENTRY_TAG = '/\{entry:([^@:}]+)(?:@([^:}]+))?/';

    public function __construct(
        private readonly Markdown $markdown,
        private readonly Settings $settings,
    ) {}

    /** @return array<string, string> Each check's label, by handle */
    public static function labels(): array
    {
        return [
            self::ALT_TEXT => t('Images without alt text', category: 'wahlberg'),
            self::H1 => t('Level 1 headings', category: 'wahlberg'),
            self::HEADING_LEVELS => t('Skipped heading levels', category: 'wahlberg'),
            self::ENTRY_LINKS => t('Links to entries that aren’t live', category: 'wahlberg'),
        ];
    }

    /**
     * The checks that are on: every one for `*`, otherwise those listed. The config
     * file isn't validated, so anything unknown it sets is ignored.
     *
     * @return list<string>
     */
    public function enabled(): array
    {
        $checks = Settings::normalizeChecks($this->settings->checks);

        if ($checks === self::EVERY) {
            return self::ALL;
        }

        return is_array($checks) ? array_values(array_intersect(self::ALL, $checks)) : [];
    }

    /**
     * What's wrong with the Markdown, one sentence each, in the order it's found.
     *
     * @param  list<string>|null  $checks  Which to run; the enabled ones by default
     * @return list<string>
     */
    public function check(MarkdownData|string|null $markdown, ?int $siteId = null, ?array $checks = null): array
    {
        $checks = array_flip($checks ?? $this->enabled());
        $source = $markdown instanceof MarkdownData ? $markdown->getRaw() : (string) $markdown;

        if ($checks === [] || trim($source) === '') {
            return [];
        }

        $flavour = $markdown instanceof MarkdownData ? $markdown->getFlavor() : WahlbergFlavor::NAME;
        $siteId ??= (int) Sites::getPrimarySite()->id;

        $problems = [];
        $images = 0;
        $links = [];
        $previous = null;

        $this->markdown->transform($source, function (Document $document) use (&$problems, &$images, &$links, &$previous, $checks): void {
            foreach ($document->iterator() as $node) {
                if ($node instanceof Image && isset($checks[self::ALT_TEXT]) && self::text($node) === '') {
                    $images++;
                } elseif ($node instanceof Link && isset($checks[self::ENTRY_LINKS])) {
                    $links[] = $node;
                } elseif ($node instanceof Heading) {
                    $level = $node->getLevel();

                    if ($level === 1 && isset($checks[self::H1])) {
                        $problems[] = t('“{heading}” is a level 1 heading. The page’s title is usually the only one, so start at level 2.', [
                            'heading' => self::quote(self::text($node)),
                        ], 'wahlberg');
                    } elseif (isset($checks[self::HEADING_LEVELS]) && $level > ($previous ?? 1) + 1) {
                        $problems[] = $previous === null
                            ? t('“{heading}” is a level {level} heading, but it’s the first one. Start at level 2.', [
                                'heading' => self::quote(self::text($node)),
                                'level' => $level,
                            ], 'wahlberg')
                            : t('“{heading}” is a level {level} heading, but the one before it is level {previous}.', [
                                'heading' => self::quote(self::text($node)),
                                'level' => $level,
                                'previous' => $previous,
                            ], 'wahlberg');
                    }

                    $previous = $level;
                }
            }
        }, $flavour);

        if ($images > 0) {
            array_unshift($problems, t('{count, plural, =1{An image has} other{# images have}} no alt text.', ['count' => $images], 'wahlberg'));
        }

        return [...$problems, ...$this->entryLinkProblems($links, $siteId)];
    }

    /**
     * The problems as a field warning: Craft renders it as one paragraph of inline
     * Markdown, so each goes on a line of its own.
     *
     * @param  list<string>  $problems
     */
    public static function warning(array $problems): ?string
    {
        return $problems === [] ? null : implode("<br>\n", $problems);
    }

    /**
     * @param  list<Link>  $links
     * @return list<string>
     */
    private function entryLinkProblems(array $links, int $siteId): array
    {
        $problems = [];

        foreach ($links as $link) {
            // The parser percent-encodes a destination's braces
            if (! preg_match(self::ENTRY_TAG, rawurldecode($link->getUrl()), $match)) {
                continue;
            }

            $entry = $this->entry($match[1], $match[2] ?? null, $siteId);
            $status = $entry?->getStatus();

            if ($entry !== null && $status === Entry::STATUS_LIVE) {
                continue;
            }

            $params = [
                'text' => self::quote(self::text($link)),
                'title' => self::quote((string) ($entry->title ?? '')),
            ];

            $problems[] = match (true) {
                $entry === null => t('“{text}” links to an entry that doesn’t exist.', $params, 'wahlberg'),
                $status === Entry::STATUS_PENDING => t('“{text}” links to “{title}”, which isn’t published yet.', $params, 'wahlberg'),
                $status === Entry::STATUS_EXPIRED => t('“{text}” links to “{title}”, which has expired.', $params, 'wahlberg'),
                default => t('“{text}” links to “{title}”, which is disabled.', $params, 'wahlberg'),
            };
        }

        return $problems;
    }

    /** The entry a tag points to, in the tag's site or the one being edited. */
    private function entry(string $ref, ?string $site, int $siteId): ?Entry
    {
        if ($site !== null) {
            $siteId = ctype_digit($site) ? (int) $site : Sites::getSiteByHandle($site)?->id;

            if ($siteId === null) {
                return null;
            }
        }

        $query = Entry::find()->siteId($siteId)->status(null);
        ctype_digit($ref) ? $query->id((int) $ref) : $query->uid($ref);
        $entry = $query->one();

        return $entry instanceof Entry ? $entry : null;
    }

    private static function text(Node $node): string
    {
        return trim(StringContainerHelper::getChildText($node, [RawMarkupContainerInterface::class]));
    }

    /**
     * Author text for a warning, which Craft reads as Markdown with HTML allowed:
     * escaped so it shows as typed.
     */
    private static function quote(string $text): string
    {
        $text = mb_strimwidth(preg_replace('/\s+/u', ' ', $text) ?? $text, 0, 60, '…');

        return addcslashes(htmlspecialchars($text, ENT_QUOTES), '\\`*_[]~=');
    }
}
