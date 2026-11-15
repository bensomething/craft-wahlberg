<?php

declare(strict_types=1);

namespace bensomething\wahlberg;

use CraftCms\Cms\Component\ComponentHelper;
use CraftCms\Cms\Element\Conditions\Contracts\ElementConditionInterface;
use CraftCms\Cms\Element\ElementIndexes;
use CraftCms\Cms\Element\ElementSources;
use CraftCms\Cms\Element\Queries\EntryQuery;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Field\Contracts\FieldInterface;
use CraftCms\Cms\Field\Link;
use CraftCms\Cms\Field\LinkTypes\BaseElementLinkType;
use CraftCms\Cms\Field\Markdown as MarkdownField;
use CraftCms\Cms\Support\Facades\ElementSources as ElementSourcesFacade;
use CraftCms\Cms\User\Elements\User;
use Illuminate\Support\Collection;

/**
 * Which entries a Markdown field's `@` menu can link to.
 *
 * The field's own link settings decide, the ones its toolbar's Link button follows:
 * no `@` menu unless Entry is an allowed link type, and then only entries from the
 * entry sources it allows that match its Selectable Entries Condition. Only entries
 * with URLs, and ones the author can view.
 */
class EntryLinks
{
    public const string LINK_TYPE = 'entry';

    /** Read per source before checking permissions, so a few the author can't see don't empty the menu. */
    private const int CANDIDATES = 100;

    public function __construct(
        private readonly ElementIndexes $indexes,
    ) {}

    /** Whether the field offers entry links at all. */
    public static function enabledFor(FieldInterface $field): bool
    {
        return $field instanceof MarkdownField
            && in_array(self::LINK_TYPE, $field->linkSettingsTypes, true)
            && isset(Link::types()[self::LINK_TYPE]);
    }

    /**
     * Entries whose title has the search in it, most recently edited first.
     *
     * @return Collection<int, Entry>
     */
    public function search(MarkdownField $field, int $siteId, string $search, User $user, int $limit): Collection
    {
        if (! self::enabledFor($field)) {
            return collect();
        }

        $linkType = $this->linkType($field);
        $condition = $linkType->getSelectionCondition();

        // One query per allowed source, as the entry picker would list them. None
        // chosen means all of them
        $queries = $linkType->sources === null
            ? [$this->query(Entry::find(), $condition)]
            : array_filter(array_map(fn (string $key) => $this->sourceQuery($key, $condition), $linkType->sources));

        // Rows come back as arrays only from a query asked for them, which these aren't
        $entries = [];

        foreach ($queries as $query) {
            foreach ($this->narrow($query, $siteId, $search)->all() as $entry) {
                if ($entry instanceof Entry) {
                    $entries[(int) $entry->id] = $entry;
                }
            }
        }

        return collect(array_values($entries))
            ->filter(fn (Entry $entry) => $entry->canView($user))
            ->sortByDesc(fn (Entry $entry) => [$entry->dateUpdated?->getTimestamp() ?? 0, $entry->id])
            ->take($limit)
            ->values();
    }

    private function linkType(MarkdownField $field): BaseElementLinkType
    {
        /** @var BaseElementLinkType */
        return ComponentHelper::createComponent([
            'type' => Link::types()[self::LINK_TYPE],
            'settings' => $field->linkSettingsTypeSettings[self::LINK_TYPE] ?? [],
        ], BaseElementLinkType::class);
    }

    /** @return EntryQuery<Entry>|null */
    private function sourceQuery(string $key, ?ElementConditionInterface $condition): ?EntryQuery
    {
        $source = ElementSourcesFacade::findSource(Entry::class, $key, ElementSources::CONTEXT_MODAL);

        if ($source === null) {
            return null;
        }

        /** @var EntryQuery<Entry> */
        return $this->indexes->buildQueryState(Entry::class, $source, $condition)['query'];
    }

    /**
     * @param  EntryQuery<Entry>  $query
     * @return EntryQuery<Entry>
     */
    private function query(EntryQuery $query, ?ElementConditionInterface $condition): EntryQuery
    {
        $condition?->modifyQuery($query);

        return $query;
    }

    /**
     * @param  EntryQuery<Entry>  $query
     * @return EntryQuery<Entry>
     */
    private function narrow(EntryQuery $query, int $siteId, string $search): EntryQuery
    {
        $query
            ->siteId($siteId)
            ->status(null)
            // Something to link to and something to call it. Nested entries and
            // sections without URLs have no URI
            ->uri(':notempty:')
            ->title(':notempty:')
            // Recently edited first: what an author links to is usually something
            // they've just been working on, which post date doesn't show
            ->reorder()
            ->orderBy('elements.dateUpdated', 'desc')
            ->orderBy('elements.id', 'desc')
            ->limit(self::CANDIDATES);

        if ($search !== '') {
            $query->title('*'.$search.'*');
        }

        return $query;
    }
}
