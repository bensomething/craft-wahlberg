# Wahlberg for Craft CMS

A Markdown field with a GitHub-style editor: a **Write** tab, a **Preview** tab, and a formatting toolbar.

- Raw Markdown in, raw Markdown out
- Server-side preview, parsed with the same parser as Craft’s `|md` filter, so the preview can’t drift from the front end
- HTML Purifier on the parsed output by default, so inline `<script>` can’t ride in on an author’s Markdown
- Markdown syntax highlighting in the Write tab, without giving up the plain textarea
- Formatting toolbar: heading, bold, italic, quote, code, link, bulleted and numbered lists, folding into a menu when the field is too narrow for them
- The editor grows to fit what’s typed, between a minimum and (optional) maximum height
- `⌘B` / `⌘I` / `⌘K` shortcuts, and Enter continues lists and blockquotes
- Buttons toggle: hit **Bold** on already-bold text and the markers come off
- Native browser undo — formatting buttons don’t blow away the undo stack
- No editor library bundled; it’s a textarea, some vanilla JS, and Craft’s own icons
- Per-field Markdown flavor, starting height, and toolbar visibility
- GraphQL support

## Requirements

- Craft CMS 5.0+
- PHP 8.2+

## Installation

```bash
composer require bensomething/craft-wahlberg
```

Or install from the control panel: **Settings → Plugins**.

## The Field

Create a field of type **Markdown** and add it to a field layout.

**Field settings**

- *Markdown Flavor* — which parser the Preview tab and the field’s `html` value use. GitHub-Flavored Markdown (the default) adds fenced code blocks, tables, strikethrough, and autolinking. The “line breaks preserved” variant additionally turns single newlines into `<br>`s. Traditional Markdown and Markdown Extra are also available.
- *Text Size* — how big the Markdown source is in the editor, 11–20px. Editing comfort only; it has no bearing on the front end.
- *Minimum Rows* — how short the editor is allowed to get. It grows from there as the author types.
- *Maximum Rows* — how tall it may grow before it starts scrolling instead. Leave blank to let it keep growing. Dragging the resize handle overrides auto-growing for that session.
- *Show Preview Tab* — with this off, the editor is source-only and the toolbar moves over to where the tabs were.
- *Show Formatting Toolbar* — hide the buttons for authors who’d rather just type. The keyboard shortcuts keep working either way.
- *Purify HTML* — see below.

## Templating

The field value is `null`, or a `MarkdownData` object:

```twig
{{ entry.body.html }}   {# the parsed HTML #}
{{ entry.body.raw }}    {# the raw Markdown, as typed #}
{{ entry.body.text }}   {# parsed, then stripped to plain text #}
{{ entry.body.flavor }} {# the field's configured flavor #}
```

`{{ entry.body }}` on its own outputs the raw Markdown, so Craft’s own filter still works if you’d rather parse it yourself:

```twig
{{ entry.body|md('gfm') }}
{{ entry.body|md(inlineOnly=true) }}
```

Empty fields are `null`, so the usual guard applies:

```twig
{% if entry.body %}
    {{ entry.body.html }}
{% endif %}
```

## Raw HTML and purification

Markdown lets authors write HTML inline, so a Markdown field is an HTML field wearing a disguise. **Purify HTML** is on by default: `entry.body.html` is run through [HTML Purifier](http://htmlpurifier.org/) after parsing, using the same defaults as Craft’s own HTML fields — which means YouTube and Vimeo iframes survive and `<script>` doesn’t.

Two things to know about how that works here:

- **It runs at output, not on save.** Craft’s CKEditor field purifies the value as it’s stored, because what’s stored *is* HTML. Here the stored value is Markdown source, and purifying source would mangle it — autolinks like `<https://example.com>` and `<` inside code fences are not markup. So purification happens each time `.html` is rendered, and the raw Markdown is never touched.
- **`|md` bypasses it.** `{{ entry.body.html }}` is purified; `{{ entry.body|md }}` runs Craft's filter over the raw value and isn’t. That’s deliberate — `.raw` has to stay pristine — but it means the protection lives on one particular path.

To change what’s allowed through, drop a JSON config file in `config/htmlpurifier/` and select it in the field’s settings, exactly as you would for a CKEditor field:

```json
{
  "HTML.SafeIframe": true,
  "URI.SafeIframeRegexp": "%^(https?:)?//(www\\.youtube\\.com/embed/|player\\.vimeo\\.com/video/|maps\\.google\\.com/)%"
}
```

Turning **Purify HTML** off renders exactly what authors type, scripts included. Reasonable when the only people editing are the ones who could edit templates anyway.

## Using the editor in your own plugin

The editor isn’t tied to the field type. Install Wahlberg as a dependency and you can put it on any textarea in the control panel.

From a template — this wraps it in Craft’s own field chrome, so label, instructions, errors and the required marker all behave as they would for any other field:

```twig
{% import 'wahlberg/editor' as wahlberg %}

{{ wahlberg.field({
    label: 'Release Notes'|t('my-plugin'),
    instructions: 'Markdown, please.'|t('my-plugin'),
    name: 'notes',
    value: settings.notes,
    errors: settings.getErrors('notes'),
}) }}
```

`wahlberg.input({ ... })` gives you the bare editor without the field chrome, and `Editor::inputHtml([ ... ])` is the same thing from PHP:

```php
use bensomething\wahlberg\Editor;

echo Editor::inputHtml([
    'name' => 'notes',
    'value' => $model->notes,
]);
```

**Options** — `name`, `value`, `id`, `toolbar`, `preview`, `highlight`, `flavor`, `fontSize`, `minRows`, `maxRows`, and `inputAttributes` (merged onto the `<textarea>`, for placeholders and the like). Anything else in the config is passed through to Craft’s field macro.

Turn `highlight` off and you get a plain textarea with the same chrome — sizing, toolbar if you want it — but no Markdown colouring:

```twig
{{ wahlberg.field({
    label: 'Notes'|t('my-plugin'),
    name: 'notes',
    value: settings.notes,
    highlight: false,
    preview: false,
    toolbar: false,
}) }}
```

The **Preview** tab works without a field behind it — it parses with whatever `flavor` you pass and always purifies, since there are no field settings to consult.

What’s public API here is the three entry points and their options. The markup they generate, the CSS class names, and the data attributes the JS binds to are all internal, and will change without a major version — so render through these rather than hand-rolling the HTML.

## GraphQL

Markdown fields resolve to a `wahlberg_Markdown` type:

```graphql
{
  entries {
    ... on article_Entry {
      body {
        raw
        html
        text
      }
    }
  }
}
```

## Editing

| Action | Shortcut |
| --- | --- |
| Bold | `⌘B` / `Ctrl+B` |
| Italic | `⌘I` / `Ctrl+I` |
| Link | `⌘K` / `Ctrl+K` |

The editor grows as the author types, between *Minimum Rows* and *Maximum Rows*. Dragging the resize handle takes over from there — once someone has picked a height by hand, it stops resizing itself.

### Syntax highlighting

The Write tab highlights Markdown as you type. It's still a plain `<textarea>` — the highlighting is a layer rendered behind it, showing the same text with the syntax picked out, while the textarea's own text is made transparent. That keeps native undo, spellcheck, selection, and form submission working exactly as they would otherwise.

Two details keep the layers locked together. Trailing spaces are rendered as non-breaking spaces, because `white-space: pre-wrap` lets ordinary trailing spaces hang with no width while the textarea's caret still advances past them. And on load the editor measures a character's rendered width in each layer and corrects any difference with `letter-spacing` — a textarea and a `<pre>` don't reliably resolve the same face from the same font stack. If something still looks off, add the `wahlberg--debug` class to the field to paint the textarea's own text in red over the layer beneath it, and read `data-advance` off the `<pre>` for the two measurements.

The tokens are styled by colour and nothing else. That constraint is load-bearing: a bold or italic cut can resolve to a face with different advance widths than the textarea's regular text, and the two layers then drift apart a fraction of a character at a time until the caret visibly misses the end of a line. Retheme it with the CSS variables on `.wahlberg` — `--wahlberg-mark`, `--wahlberg-heading`, `--wahlberg-strong`, `--wahlberg-em`, `--wahlberg-code`, `--wahlberg-link`, `--wahlberg-url`, `--wahlberg-quote` — but keep to colour if you want the caret to stay put.

Pressing Enter at the end of a list item or blockquote carries the marker onto the next line, and numbered lists count up. Pressing Enter on an empty item ends the list. Selecting a URL before hitting the link button (or `⌘K`) drops it straight into the link’s target.

## Why “Wahlberg”?

Marky Mark. Markdown. Sorry.
