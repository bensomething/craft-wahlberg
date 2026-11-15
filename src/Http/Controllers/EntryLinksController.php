<?php

declare(strict_types=1);

namespace bensomething\wahlberg\Http\Controllers;

use bensomething\wahlberg\EntryLinks;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Field\Markdown as MarkdownField;
use CraftCms\Cms\Support\Facades\Fields;
use CraftCms\Cms\Support\Facades\Sites;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

use function CraftCms\Cms\currentUserElement;

/**
 * The `@` menu's suggestions for a Markdown field (see {@see EntryLinks} for which
 * entries), as text expander options that insert a Markdown link to the entry.
 *
 * The link's URL is a reference tag, `{entry:12@1:url}`, as the field's own link
 * button writes, so it follows the entry when its URI changes and Craft tracks the
 * reference.
 */
class EntryLinksController
{
    public function __invoke(Request $request, EntryLinks $entryLinks): JsonResponse
    {
        $validated = $request->validate([
            'field' => ['required', 'integer'],
            'query' => ['nullable', 'string', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
            'site' => ['nullable', 'integer'],
        ]);

        // Craft's user element, which canView() takes. The request's user is the
        // installation's auth model, which may be the app's own User class
        $user = currentUserElement();
        abort_if($user === null, 403);

        $field = Fields::getFieldById((int) $validated['field']);
        abort_unless($field instanceof MarkdownField, 404);

        $site = Sites::getSiteById((int) ($validated['site'] ?? 0)) ?? Sites::getPrimarySite();
        $search = trim(Str::after((string) ($validated['query'] ?? ''), '@'));

        $options = $entryLinks
            ->search($field, (int) $site->id, $search, $user, (int) ($validated['limit'] ?? 10))
            ->map(fn (Entry $entry) => [
                'label' => (string) $entry->title,
                'value' => sprintf('[%s]({entry:%d@%d:url})', self::escape((string) $entry->title), $entry->id, (int) $site->id),
                'keywords' => array_filter([$entry->slug]),
                'data' => ['hint' => (string) $entry->getSection()?->getUiLabel()],
            ])
            ->all();

        return new JsonResponse($options);
    }

    /** So a title with brackets in it doesn't end the link text early. */
    private static function escape(string $text): string
    {
        return str_replace(['\\', '[', ']'], ['\\\\', '\\[', '\\]'], $text);
    }
}
