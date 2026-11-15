# Wahlberg for Craft CMS

Supercharge Craft’s native Markdown field.

> [!NOTE]
> This is the Craft 6 line, in development. Craft 6 ships a Markdown field of its own, with a toolbar, a server-rendered preview and reference tags, so Wahlberg 2 doesn’t bring a field type. It adds to the native field instead: snippets, `@` links to entries, a Markdown flavour with alerts and footnotes, and template helpers.
>
> For Craft 5, use [Wahlberg 1.x](https://github.com/bensomething/craft-wahlberg/tree/develop), the full field.

## Requirements

- Craft CMS 6
- PHP 8.5+

## Snippets

Set snippets up under **Settings → Plugins → Wahlberg**. Each has a label, a handle, its Markdown, and optionally the handles of the fields that offer it. Without any, every Markdown field does.

Picked mid-sentence, a snippet moves to a new paragraph, with a blank line either side, since most are blocks like a quote, a table or a figure. Tick **Inline** for one that should go in exactly where it’s typed, like a `<kbd>` or an abbreviation.

In a Markdown field, type `/` at the start of a line or after a space to open the snippets menu, and keep typing to filter it.

In a snippet’s Markdown:

- `$1` to `$9` are stops, visited in order on Tab and ⇧Tab. Esc ends the run.
- `${1:like this}` is a stop with a default: the text goes in, and landing on the stop selects it.
- `$0` is where the caret ends up, after any numbered stops. Without one it lands at the end.

## Entry links

Type `@` in a Markdown field, then part of an entry’s title, to link to it. The link goes in as Markdown with a reference tag for its URL, `[Getting started]({entry:12@1:url})`, so it follows the entry if its URI changes, and Craft tracks the reference. The menu lists entries with URLs that the author can view, in the site being edited, most recently edited first.

It follows the field’s own link settings, the ones its toolbar’s Link button uses: a field offers `@` only when **Entry** is one of its **Allowed Link Types**, and then only entries from its **Entry Sources** that match its **Selectable Entries Condition**.

## The Wahlberg flavour

Wahlberg adds a **Wahlberg** option to the Markdown field’s **Markdown Flavor** setting. It’s everything Craft’s GitHub-Flavored Markdown does, plus:

- **Alerts**, written the way GitHub reads them:

  ```markdown
  > [!WARNING]
  > Mind the gap.
  ```

  `NOTE`, `TIP`, `IMPORTANT`, `WARNING` and `CAUTION` work, in any case. They render with GitHub’s classes, so CSS written for GitHub-style alerts works as it is:

  ```html
  <div class="markdown-alert markdown-alert-warning">
  <p class="markdown-alert-title">Warning</p>
  <p>Mind the gap.</p>
  </div>
  ```

  The titles are translated in the `site` category.
- **Footnotes**: `Text.[^1]`, and `[^1]: The note.` anywhere below.
- **Highlights**: `==marked==` becomes `<mark>marked</mark>`.
- **Heading ids**, from each heading’s text, so a page can link to `#getting-started`.

It works anywhere Craft parses Markdown, so `{{ text|md(flavor='Wahlberg') }}` uses it too. Uninstalling Wahlberg moves fields on the Wahlberg flavour back to GitHub-Flavored Markdown.

## Template helpers

Twig filters for a Markdown field’s value, or any string of Markdown:

```twig
{# A table of contents, from the headings #}
<ol>
  {% for heading in entry.body|toc(2, 3) %}
    <li class="level-{{ heading.level }}"><a href="#{{ heading.id }}">{{ heading.text }}</a></li>
  {% endfor %}
</ol>

{# A description, cut at a word #}
<meta name="description" content="{{ entry.body|excerpt(155) }}">

{# Minutes to read, at 200 words a minute, or your own pace #}
{{ entry.body|readingTime }} min read
{{ entry.body|readingTime(250) }} min read

{# The text, without Markdown #}
{{ entry.body|plainText }}

{# The Markdown, with reference tags resolved, for an llms.txt #}
{{ entry.body|parseMarkdownRefs }}
```

- `toc(minLevel = 1, maxLevel = 6)` returns each heading’s `level`, `text` and `id`. The ids are the ones the Wahlberg flavour gives headings, so the links work on a field that uses it.
- `excerpt(length = 160, suffix = '…')` and `plainText` leave out images, raw HTML and footnote markers.
- `parseMarkdownRefs(siteId = null)` leaves the Markdown as it is and resolves only the reference tags, except inside code, where they stay as typed.

A field’s value is read in its field’s flavour. A string is read in the Wahlberg flavour.

## Configuration

To fix snippets or the trigger in code, publish the config file:

```bash
php artisan vendor:publish --tag=wahlberg
```

It lands at `config/craft/wahlberg.php`. A key set there replaces the saved setting and shows read-only on the settings screen. See the comments in it for the format.

## Extending

Plugins and modules can add snippets of their own by listening for `bensomething\wahlberg\Events\SnippetsRegistering` and adding to `$event->snippets`, in the config file’s format. A handle the installation defines wins.
