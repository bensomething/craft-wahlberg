# Wahlberg for Craft CMS

A Markdown field with a GitHub-style editor: a **Write** tab, a **Preview** tab, and a formatting toolbar.

> [!NOTE]
> Wahlberg is in beta. The templating surface, `|marky` filter, `wahlberg_Markdown` GraphQL type, `Editor::inputHtml()` options, both events, and the `config/wahlberg.php` snippet format are settled. The field’s own settings may still move.

- Raw Markdown in, raw Markdown out
- Server-side preview, parsed with the same parser as Craft’s `|md` filter, so the preview can’t drift from the front end
- HTML Purifier on the parsed output by default, so inline `<script>` can’t ride in on an author’s Markdown
- Craft reference tags like `[Read more]({entry:123:url})` resolved on output, and left alone inside code
- Markdown syntax highlighting in the Write tab, without giving up the plain textarea
- A band behind the line being written, over every row a wrapped line takes
- Formatting toolbar: headings, bold, italic, strikethrough, quote, code, link, entry and asset pickers, bulleted and numbered lists, folding into a menu when the field is too narrow for them
- Or a floating toolbar, in a panel over the selection rather than a strip above the editor
- Entry and asset links written as reference tags, so they survive a slug change or a replaced file
- Snippets: blocks of Markdown you define, dropped in from the toolbar
- The editor grows to fit what’s typed, between a minimum and (optional) maximum height
- `⌘B` / `⌘I` / `⌘K` shortcuts, `⌘⇧E` and `⌘⇧U` for the element pickers, `⌘⇧K` for snippets, `⌘⇧P` to preview, `⌘⇧F` for the floating toolbar, and Enter continues lists and blockquotes
- Buttons toggle: hit **Bold** on already-bold text and the markers come off
- Native browser undo, so formatting buttons don’t blow away the undo stack
- No editor library bundled: it’s a textarea, some vanilla JS, and Craft’s own icons
- Per-field Markdown flavour, toolbar, sizing, placeholder, character or byte limit, and counts
- GraphQL support
- Reusable outside the field type: drop the editor into your own plugin’s settings from Twig or PHP

## Requirements

- Craft CMS 5
- PHP 8.2+

## Installation

```bash
composer require bensomething/craft-wahlberg:^1.0.0-beta
```

The `-beta` in the constraint is what lets Composer install it under a project’s default `stable` minimum stability.

Or install from the control panel: **Settings → Plugins**.

## The Field

Create a field of type **Markdown** and add it to a field layout.

**Field settings**

| Setting | Default | |
| --- | --- | --- |
| **Markdown Flavour** | GitHub-Flavoured | Which parser the Preview tab and the `html` value use. GFM adds fenced code blocks, tables, strikethrough and autolinking; Traditional Markdown and Markdown Extra are also available. |
| **Preserve Line Breaks** | On | GFM only. Turns a single newline into a `<br>`, the way GitHub’s comment boxes do. Turn it off for Markdown that’s hard-wrapped and meant to reflow. This is the parser’s `gfm-comment` flavour, which is what `.flavour` reports. |
| **Inline Only** | Off | Render without the wrapping `<p>`, for a heading or strapline going into markup of its own. Emphasis, links and code still parse. |

**Editor**

| Setting | Default | |
| --- | --- | --- |
| **Text Size** | 14px | The Markdown source in the editor, 11–20px. Editing comfort only — no bearing on the front end. |
| **Minimum Rows** | 2 | How short the editor may get, 1 or more. It grows from there as the author types. |
| **Maximum Rows** | none | How tall it may grow before it scrolls instead. Blank lets it keep growing. Dragging the resize handle overrides auto-growing for that session. |
| **Placeholder Text** | none | Shown while the field is empty. |
| **Field Limit** | none | The most characters or bytes of Markdown the field accepts, enforced on save. Counts the source an author types, not the HTML it renders to. Bytes matter once the text stops being ASCII: an emoji is one character and four bytes. |

**Toolbar**

| Setting | Default | |
| --- | --- | --- |
| **Show Preview Tab** | On | Off makes the editor source-only, and the toolbar moves to where the tabs were. |
| **Show Formatting Toolbar** | On | The keyboard shortcuts keep working either way. |
| **Floating Toolbar** | Off | Puts the buttons in a panel over the selection rather than in a strip above the editor. Nothing formatting-related is on screen until an author selects something or hits `⌘⇧F` — see [Floating](#floating). |
| **Toolbar Buttons** | all but Heading 1, Heading 3–6 and the guide | Which buttons the toolbar offers — see [The toolbar](#the-toolbar). |
| **Show Syntax Highlighting** | On | Off leaves a plain textarea with the same sizing, toolbar and Preview tab. The escape hatch if a font stack won’t hold the highlighted layer and the textarea together. |
| **Show Stats** | Off | Character, word and line counts under the editor, with the field limit alongside when there is one. |

**Parsing**

| Setting | Default | |
| --- | --- | --- |
| **Parse Reference Tags** | On | See [Reference tags](#reference-tags). |
| **Encode HTML** | Off | Encode HTML before parsing, so a tag an author types shows up as text. See [Raw HTML and purification](#raw-html-and-purification). |
| **Purify HTML** | On | See [Raw HTML and purification](#raw-html-and-purification). |
| **HTML Purifier Config** | Default | Which JSON config in `config/htmlpurifier/` to sanitise with. |

**Snippets**

Only shown when `config/wahlberg.php` defines any. See [Snippets](#snippets).

| Setting | Default | |
| --- | --- | --- |
| **Available Snippets** | all | Which of the defined snippets this field’s **Snippets** button offers. |

**Assets**

These apply to the toolbar’s **Asset** button.

| Setting | Default | |
| --- | --- | --- |
| **Available Volumes** | all | Which volumes the button may pick from. |
| **Show unpermitted volumes** | Off | Whether to offer volumes the author can’t view. |
| **Show unpermitted files** | Off | Whether to offer files uploaded by other authors, per Craft’s “View files uploaded by other users” permission. |

## The toolbar

Every button is optional, and which ones a field offers is up to *Toolbar Buttons*:

| Button | Shortcut | What it writes |
| --- | --- | --- |
| **Heading 1**–**Heading 6** | | that level exactly, so clicking **H3** on an H1 line makes it an H3 |
| **Bold** | <kbd>⌘B</kbd> | `**` around the selection, or the word under the caret |
| **Italic** | <kbd>⌘I</kbd> | `_` around the selection, or the word under the caret |
| **Strikethrough** | | `~~` around the selection, or the word under the caret |
| **Quote** | | `> ` |
| **Code** | | `` ` `` around a selection on one line, a fence around one spanning several |
| **Link** | <kbd>⌘K</kbd> | `[text](url)`, or `[](url)` with the caret in the brackets when a URL was selected |
| **Entry** | <kbd>⌘⇧E</kbd> | opens Craft’s element selector — see below |
| **Asset** | <kbd>⌘⇧U</kbd> | opens Craft’s element selector — see below |
| **Bulleted list**, **Numbered list** | | `- ` and `1. `, toggling between each other rather than stacking up |
| **Snippets** | <kbd>⌘⇧K</kbd> | blocks of Markdown you define — see [Snippets](#snippets) |
| **Markdown guide** | | a syntax cheatsheet, in a popover off the button |

Shortcuts work whether or not the button is shown, so a field with the toolbar switched off still has all of them. On Windows and Linux, <kbd>Ctrl</kbd> stands in for <kbd>⌘</kbd>. **Asset** is <kbd>⌘⇧U</kbd> rather than the <kbd>⌘⇧A</kbd> you’d expect, because macOS browsers keep that one for themselves and it never reaches the page.

The buttons fold into a menu when the toolbar is too narrow to hold them all. **Snippets** and **Markdown guide** are the exceptions: each opens a panel rather than writing anything, so they stay put at the end.

### Floating

With *Floating Toolbar* on, the buttons leave the header and appear in a panel over the text instead — pointed at whatever’s selected, above it or below it depending on which side has room.

It’s worth being clear about what the setting costs, because it isn’t a look. A field with a floating toolbar opens showing no formatting buttons at all, the **Markdown guide** among them; they only turn up once an author selects something. That’s a good trade for authors who write Markdown all day and a bad one for authors who don’t, which is what the setting is really choosing between.

Half of what the toolbar offers goes in at the caret rather than around a selection — a heading, a list, a quote, an entry, a snippet — so the panel doesn’t only answer to selections:

| | |
| --- | --- |
| A selection | the panel appears, pointed at it |
| <kbd>⌘⇧F</kbd> | the panel at the caret, with nothing selected, until <kbd>Esc</kbd> |
| <kbd>Esc</kbd> | puts it away until something else is selected |

It stays put while a menu it opened is up, follows the text as the field scrolls or reflows, and goes away when the field loses focus, when the **Preview** tab comes up, or when the text it was pointing at scrolls out of a field that’s hit its maximum height.

### Headings

However many heading levels you tick, the toolbar shows **one** control — six near-identical H icons in a row is a lot of toolbar to say one thing. What changes is its shape:

| Levels ticked | What authors get |
| --- | --- |
| None | no heading control at all |
| One | a button that applies that level outright |
| Two or more | a dropdown listing them |

The icon is the same plain **H** either way, with the level named in the tooltip, so the toolbar doesn’t shift about between fields. New fields start with **Heading 2** on its own: level 1 is nearly always the element’s own title, so body content starts below it.

### Entry and Asset

Both open Craft’s element selector. **Entry** writes a link; **Asset** writes an image as `![alt](…)` and anything else as a link, taking the alt text from the asset when it has some.

With *Parse Reference Tags* on, both write a reference tag rather than a URL:

```markdown
[The Difference Engine]({entry:19:url})

![Ada Lovelace]({asset:41:url})
```

so the link survives a slug change, or follows the file if it’s replaced or moved. With reference tags off there’s nothing to resolve the tag later, so they write the URL instead — and an entry with no URL of its own writes an empty one.

## Snippets

Blocks of Markdown authors can drop in from the toolbar, defined in `config/wahlberg.php`. Copy [`src/config.php`](src/config.php) to start from a working example.

```php
return [
    'snippets' => [
        'callout' => [
            'label' => 'Callout',
            'icon' => 'circle-info',
            'body' => "> **Note**\n> \$0\n",
        ],

        // Shorthand: a body on its own, labelled from its key
        'leadIn' => "**\$SELECTION**\n\n\$0",
    ],
];
```

`icon` is optional — any name from Craft’s set, which is Font Awesome’s solid icons. One that doesn’t name an icon gets a neutral stand-in, so the labels line up either way.

Two markers are understood, both optional:

- **`$0`** is where the caret ends up. Without one it lands at the end.
- **`$SELECTION`** is replaced by whatever the author had selected, so a snippet can wrap their text rather than only ever landing beside it. It’s empty when nothing was selected, and every occurrence is replaced.

Mind the quoting: inside a double-quoted PHP string, `$0` and `$SELECTION` read as variables, so escape them as `\$0` and `\$SELECTION`. Single quotes avoid that but cost you `\n`. Heredocs interpolate; nowdocs (`<<<'MD'`) don’t.

**Why a config file and not a settings screen?** A snippet is a contract with the templates and CSS that render it — a callout only looks like a callout because your front end styles what it emits. So the person writing one should be the person who can also write that, and the definition should travel with the code in version control. Which snippets a *given field* offers is a field setting, under **Available Snippets** — the same split `config/htmlpurifier/` already uses.

A field that has never been saved against a snippet offers all of them, so adding one to the config file reaches every existing field without editing each one. With no config file the **Snippets** button hides itself rather than opening an empty menu, and the field settings drop the section to match.

### Opening the menu

<kbd>⌘⇧K</kbd> opens it **at the caret**, which is where the snippet is going. Clicking the toolbar button opens it under the button instead, since that's where the eye already is.

The shortcut doesn't need the button: it works with **Snippets** unticked in *Toolbar Buttons*, and with the toolbar switched off altogether. Arrows and <kbd>Tab</kbd> move through the list, <kbd>Enter</kbd> inserts, <kbd>Esc</kbd> closes and puts the caret back.

**Mind what your fields render.** With *Purify HTML* on — the default — raw HTML in a snippet is sanitised on the way out, and HTML Purifier only knows HTML 4: `<details>` and `<summary>` are dropped entirely, and iframes survive only for the hosts `config/htmlpurifier/` allows. Markdown *inside* a raw HTML block isn’t parsed either, whatever the purifier does. A snippet that emits Markdown works everywhere; one that emits HTML is worth checking in the Preview tab first.

### From a plugin

Plugins can add snippets to the pool every field picks from:

```php
use bensomething\wahlberg\events\RegisterSnippetsEvent;
use bensomething\wahlberg\helpers\Snippets;
use yii\base\Event;

Event::on(
    Snippets::class,
    Snippets::EVENT_REGISTER_SNIPPETS,
    function(RegisterSnippetsEvent $event) {
        $event->snippets['productSpec'] = [
            'label' => Craft::t('my-plugin', 'Product spec'),
            'body' => "{spec:\$0}\n",
        ];
    }
);
```

A handle already defined in `config/wahlberg.php` wins, so an installation can always overrule a plugin about its own site. Plugins rendering the editor directly can skip the pool entirely and pass definitions to `Editor::inputHtml()` instead — see [Using the editor in your own plugin](#using-the-editor-in-your-own-plugin).

## Templating

The field value is `null`, or a `MarkdownData` object:

```twig
{{ entry.body.html }}   {# the parsed HTML #}
{{ entry.body.raw }}    {# the raw Markdown, as typed #}
{{ entry.body.text }}   {# parsed, then stripped to plain text #}
{{ entry.body.flavour }} {# the flavour it was parsed with #}
```

`{{ entry.body }}` on its own outputs the raw Markdown, so Craft’s own filter still works if you’d rather parse it yourself:

```twig
{{ entry.body|md('gfm') }}
{{ entry.body|md(inlineOnly=true) }}
```

### The `|marky` filter

For Markdown that isn’t in a Markdown field, whether a plain text field, a plugin setting, or a string you built in the template, `|marky` parses it the way the field does: reference tags resolved, HTML purified.

```twig
{{ entry.summary|marky }}
{{ entry.summary|marky(flavour='original') }}
{{ entry.summary|marky(refs=false, purify=false) }}
```

Arguments are `flavour`, `refs`, `purify`, `purifierConfig` and `siteId`, all optional.

Craft’s `|md` is untouched and still the right choice when plain Markdown parsing is all you want. The difference is what each one does beyond parsing:

| | `\|md` | `\|marky` | `.html` |
| --- | --- | --- | --- |
| Parses Markdown | ✅ | ✅ | ✅ |
| Resolves reference tags | ❌ | ✅ | ✅ |
| Purifies HTML | ❌ | ✅ | ✅ |
| Uses the field’s settings | ❌ | only when piped a field value | ✅ |

Piping a field value, `{{ entry.body|marky }}`, is the same as `{{ entry.body.html }}`, since it takes the field’s own settings. It’s worth doing only to override one of them for a single render:

```twig
{{ entry.body|marky(refs=false) }}
```

Empty fields are `null`, so the usual guard applies:

```twig
{% if entry.body %}
    {{ entry.body.html }}
{% endif %}
```

## Reference tags

Craft’s [reference tags](https://craftcms.com/docs/5.x/system/reference-tags.html) work in Markdown fields, and are resolved when the field renders. **Parse Reference Tags** is on by default.

```markdown
[Read the docs]({entry:123:url}), see also {entry:my-section/some-entry:title}.

![Diagram]({asset:456:url})
```

They’re resolved on the way out, not on save, so an entry that changes its slug doesn’t leave a trail of dead links behind it. An unresolvable tag falls back to whatever Craft’s fallback syntax says, or to the tag itself:

```markdown
{entry:999:title || Something else}
```

Two things worth knowing:

- **Code is left alone.** A reference tag in a fenced block or an inline code span renders as the author typed it, which is what you want when the thing you’re documenting *is* reference tags. This works because tags are resolved after parsing, when the parser has already decided what counts as code, so there’s no second guess at Markdown’s fence rules to get wrong.
- **Resolved values are purified.** Whatever a tag resolves to goes through HTML Purifier along with the rest of the content, assuming **Purify HTML** is on.

On a multi-site install, tags resolve against the site the element is being rendered in. Override that per tag with Craft’s own `@` syntax, `{entry:123@german:url}`, or for a whole render with `{{ text|marky(siteId=2) }}`.

## Raw HTML and purification

Markdown lets authors write HTML inline, so a Markdown field is an HTML field wearing a disguise. **Purify HTML** is on by default: `entry.body.html` is run through [HTML Purifier](http://htmlpurifier.org/) after parsing, using the same defaults as Craft’s own HTML fields, which means YouTube and Vimeo iframes survive and `<script>` doesn’t.

Two things to know about how that works here:

- **It runs at output, not on save.** Craft’s CKEditor field purifies the value as it’s stored, because what’s stored *is* HTML. Here the stored value is Markdown source, and purifying source would mangle it, since autolinks like `<https://example.com>` and `<` inside code fences are not markup. So purification happens each time `.html` is rendered, and the raw Markdown is never touched.
- **`|md` bypasses it.** `{{ entry.body.html }}` is purified. `{{ entry.body|md }}` runs Craft's filter over the raw value and isn’t. That’s deliberate, since `.raw` has to stay pristine, but it means the protection lives on one particular path. `|marky` is on that path, `|md` isn’t.

To change what’s allowed through, drop a JSON config file in `config/htmlpurifier/` and select it in the field’s settings, exactly as you would for a CKEditor field:

```json
{
  "HTML.SafeIframe": true,
  "URI.SafeIframeRegexp": "%^(https?:)?//(www\\.youtube\\.com/embed/|player\\.vimeo\\.com/video/|maps\\.google\\.com/)%"
}
```

Turning **Purify HTML** off renders exactly what authors type, scripts included. Reasonable when the only people editing are the ones who could edit templates anyway.

### Encoding instead

**Encode HTML** is the stricter option, and a different one. Purifying parses the HTML and then drops what isn’t safe; encoding never lets it be HTML at all. An author who types `<em>tag</em>` gets those characters back on the page rather than an emphasis, and a `<script>` shows up as text.

Use it for fields where HTML has no business being — a strapline, a caption, a field authored by people you’d rather not hand an `<iframe>` to. Markdown itself carries on working: `**bold**` is still bold, it’s only the raw HTML that goes.

Encoding forces Craft’s `pre-encoded` parser, which is Traditional Markdown with the escaping it would otherwise do inside code taken out. Without that, a fenced block would come back showing `&amp;lt;` where the author typed `<`. The flavour selector is disabled while **Encode HTML** is on for that reason, and `.flavour` reports `pre-encoded`.

The two settings are independent, and belt-and-braces is fine: encoding removes the HTML, purifying then sanitises whatever the parser itself produced.

## Using the editor in your own plugin

The editor isn’t tied to the field type. Install Wahlberg as a dependency and you can put it on any textarea in the control panel.

From a template. This wraps it in Craft’s own field chrome, so label, instructions, errors and the required marker all behave as they would for any other field:

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

**Options**: `name`, `value`, `id`, `toolbar`, `floating`, `buttons`, `preview`, `highlight`, `stats`, `flavour`, `fontSize`, `minRows`, `maxRows`, `placeholder`, `charLimit`, `byteLimit`, `refTags`, `assetSources`, `assetCriteria`, `snippets`, and `inputAttributes` (merged onto the `<textarea>`). Anything else in the config is passed through to Craft’s field macro.

`snippets` takes handles from `config/wahlberg.php`, or `*` for all of them. Pass a map of `handle => {label, body}` instead and the editor uses those directly, for a plugin shipping snippets of its own rather than borrowing the installation’s.

The ones you leave out fall back to the same defaults a Markdown field starts with: 14px text, a 2-row minimum, no maximum, and the same toolbar. An editor rendered from another plugin matches one in a field layout without having to be configured to.

`buttons` takes the command names `Editor::commands()` lists, in any order — the toolbar keeps its own, and drops a group nothing was picked from rather than leaving its divider hanging:

```twig
{{ wahlberg.field({
    label: 'Notes'|t('my-plugin'),
    name: 'notes',
    value: settings.notes,
    buttons: ['bold', 'italic', 'link'],
}) }}
```

`charLimit` and `byteLimit` only draw the counter, and only when `stats` is on. Enforcing them is the field type’s job, so validate the value yourself out here.

Turn `highlight` off and you get a plain textarea with the same chrome, sizing and toolbar included, but no Markdown colouring:

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

The **Preview** tab works without a field behind it. It parses with whatever `flavour` you pass and always purifies, since there are no field settings to consult. There’s no *Preserve Line Breaks* option out here: `flavour` takes a parser flavour directly, so pass `gfm` to turn line breaks off and `gfm-comment` (the default) to keep them.

For somewhere a value is shown rather than edited, `Editor::staticHtml([ ... ])` renders the Markdown as it was written on the same surface, in the same type, with no textarea and nothing to run:

```php
echo Editor::staticHtml([
    'value' => $model->notes,
]);
```

**Options**: `value` and `fontSize`.

Reach for it anywhere the JavaScript won’t be there. Disabling the editor’s inputs isn’t enough on its own: the textarea paints its own text transparent so the highlighted layer behind it shows through, and that layer is filled by the JS. This is what a Markdown field renders in a revision, and in any other read-only form Craft builds.

What’s public API here is the four entry points and their options. The markup they generate, the CSS class names, and the data attributes the JS binds to are all internal and will change without a major version, so render through these rather than hand-rolling the HTML.

### Hooking the Preview tab

`PreviewController::EVENT_MODIFY_PREVIEW` hands you the preview’s HTML after parsing, reference tags and purification — so a listener can add markup the purifier would otherwise strip, inline SVG being the case it exists for. `ReferenceTags::outsideCode()` is there if you want to leave tokens inside code fences as the author typed them.

Two things to be deliberate about, both because it runs after the sanitiser: what you add has to be safe on its own account, and the preview is now a step ahead of `entry.body.html` unless you ship a filter that puts it back on the template side. [`ModifyPreviewEvent`](src/events/ModifyPreviewEvent.php) has a worked example.

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

Enter continues a list or a blockquote onto the next line, and ends it on an empty item. The formatting shortcuts are in [The toolbar](#the-toolbar).

<kbd>⌘⇧P</kbd> swaps between **Write** and **Preview**, from anywhere in the field, and puts the caret back where it was on the way in. It does nothing on a field with *Show Preview Tab* off, or while there’s nothing written to preview.

The editor grows as the author types, between *Minimum Rows* and *Maximum Rows*. Dragging the resize handle takes over from there. Once someone has picked a height by hand, it stops resizing itself.

### The header

Once a field fills more than half the window, its header sticks: the tabs and the toolbar hold still and the text scrolls under them, so the buttons are still there when the writing has run past where they were. That’s measured on the **Write** tab whichever tab is up — rendered Markdown is shorter than the source it came from, and a header that came and went as you switched would be worse than one that never held still.

Below that they scroll away with everything else. A short field is gone almost as soon as its header is, so sticking it would only mean sliding the strip over the last few rows on the way past — motion in exchange for nothing.

It stops below Craft’s own page header, which pins itself to the top of the window once the page scrolls. Craft has no token for that header’s height and it isn’t a fixed number, so the editor measures it — and treats it as zero in a slideout, a modal, or anywhere else scrolling in a box of its own, since the field’s header is already stopping below that thing’s chrome.

There’s no setting for it and no threshold to tune. If your control panel has put something else along the top, `--wahlberg-sticky-top` on `.wahlberg` overrules the measurement.

### Keeping your place

Switching between **Write** and **Preview** halfway down a long field puts you back where you were, rather than at the top.

What’s matched is structure, not distance. The two panes hold the same content at wildly different lengths — a link is a URL’s worth of source and a word of rendered text, and a reference tag is worse — so a percentage or a pixel offset would land somewhere arbitrary. Instead the source is split into the blocks the parser turns into elements, and the block at the top of one pane is the element put at the top of the other.

Blocks and elements come out one for one nearly always. Where they don’t — a list with blank lines between its items is several blocks of source and a single `<ul>` — the position is scaled rather than trusted, which lands in the right region instead of on the wrong paragraph. At the top of a field, switching does nothing at all.

### The current line

The line being written carries a band behind it, the way a code editor does. A long line wraps over several rows and the band covers all of them, since what it marks is the line the author is on rather than the row the caret is in — in Markdown that block is usually the paragraph.

It’s up only while the field has focus and nothing is selected: a selection already says where the author is, and a page of fields each wearing a band says nothing at all.

In light mode it’s `--gray-050`, the same step on Craft’s ramp the field’s header strip sits on; in dark, a 3% lift off whatever the surface is. Nothing else about the field’s colours changes.

There’s no setting for it. Retheme or switch it off with the CSS variables on `.wahlberg`:

| Variable | Default | |
| --- | --- | --- |
| `--wahlberg-active-line` | `--gray-050`, or a 3% lift in dark mode | `transparent` to do without |
| `--wahlberg-active-line-pad` | `1px` | how far the band stands proud of the row, top and bottom |
| `--wahlberg-line-height` | `1.6` | the row itself, which the caret and the selection are drawn to as well |

`--wahlberg-line-height` is the one to reach for if the caret looks too tall for the text: a browser draws it to the full row, so the only way to shorten it is to tighten the row. Both text layers take it from the same place on purpose — two layers on different line heights is the one mismatch the editor can’t measure its way out of.

### Syntax highlighting

The Write tab highlights Markdown as you type, and it’s still a plain `<textarea>` — the colour comes from a layer rendered behind it, with the textarea’s own text made transparent. Native undo, spellcheck, selection and form submission all behave as they otherwise would.

Bold and italic are used only where the font family has real cuts for them. The editor measures on load and falls back to colour alone where a fabricated cut would advance wider and pull the two layers apart. Nothing is lost by that: in Markdown source the `**` and `_` are on screen anyway.

Retheme with the CSS variables on `.wahlberg`: `--wahlberg-mark`, `--wahlberg-heading`, `--wahlberg-strong`, `--wahlberg-em`, `--wahlberg-code`, `--wahlberg-link`, `--wahlberg-url`, `--wahlberg-quote`. Keep to colour, since setting weight or slope here goes around that measurement. Each defaults to a step on Craft’s own ramp rather than a fixed hex, so a control panel theme that redeclares the palette — its dark mode included — moves the editor with it.

If the two layers ever look out of step, add the `wahlberg--debug` class to the field to paint the textarea’s own text in red over the layer beneath it.

## Why “Wahlberg”?

Mark Wahlberg. Marky Mark. Markdown. I hate myself.
