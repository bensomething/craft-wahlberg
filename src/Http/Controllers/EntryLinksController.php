<?php

declare(strict_types=1);

namespace bensomething\wahlberg\Http\Controllers;

use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Support\Facades\Sites;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

use function CraftCms\Cms\currentUserElement;

/**
 * The `@` menu's suggestions: entries with a URL whose title has what was typed
 * after the `@`, most recently edited first, as text expander options that insert a Markdown link to the entry.
 *
 * The link's URL is a reference tag, `{entry:12@1:url}`, as the field's own link
 * button writes for assets, so it follows the entry when its URI changes and Craft
 * tracks the reference.
 */
class EntryLinksController
{
    /** Read before checking permissions, so a few the author can't see don't empty the menu. */
    private const int CANDIDATES = 100;

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'query' => ['nullable', 'string', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
            'site' => ['nullable', 'integer'],
        ]);

        // Craft's user element, which canView() takes. The request's user is the
        // installation's auth model, which may be the app's own User class
        $user = currentUserElement();
        abort_if($user === null, 403);
        $search = trim(Str::after((string) ($validated['query'] ?? ''), '@'));
        $site = Sites::getSiteById((int) ($validated['site'] ?? 0)) ?? Sites::getPrimarySite();

        $query = Entry::find()
            ->siteId($site->id)
            ->status(null)
            // Something to link to and something to call it. Nested entries and
            // sections without URLs have no URI
            ->uri(':notempty:')
            ->title(':notempty:')
            // Recently edited first: what an author links to is usually something
            // they've just been working on, which post date doesn't show
            ->orderBy('elements.dateUpdated', 'desc')
            ->orderBy('elements.id', 'desc')
            ->limit(self::CANDIDATES);

        if ($search !== '') {
            $query->title('*'.$search.'*');
        }

        $options = $query->collect()
            ->filter(fn (Entry $entry) => $entry->canView($user))
            ->take((int) ($validated['limit'] ?? 10))
            ->map(fn (Entry $entry) => [
                'label' => (string) $entry->title,
                'value' => sprintf('[%s]({entry:%d@%d:url})', self::escape((string) $entry->title), $entry->id, $site->id),
                'keywords' => array_filter([$entry->slug]),
                'data' => ['hint' => (string) $entry->getSection()?->getUiLabel()],
            ])
            ->values()
            ->all();

        return new JsonResponse($options);
    }

    /** So a title with brackets in it doesn't end the link text early. */
    private static function escape(string $text): string
    {
        return str_replace(['\\', '[', ']'], ['\\\\', '\\[', '\\]'], $text);
    }
}
