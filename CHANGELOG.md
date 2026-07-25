# Changelog

## Unreleased

- Added a **Show Syntax Highlighting** field setting, on by default. Turning it off leaves a plain textarea with the same sizing, toolbar and Preview tab, matching what `highlight: false` already did for the standalone editor.
- Everything named “flavor” is now spelled **flavour**, including `entry.body.flavour` in templates, the `flavour` argument to `|marky`, and the `flavour` option on the editor macros. Fields saved under the old spelling carry it over on their own. The parser names themselves are untouched, so `gfm` and `gfm-comment` are still what you pass.
- The “GitHub-Flavoured Markdown (line breaks preserved)” flavour is now a **Preserve Line Breaks** setting alongside the GitHub flavour, on by default, so a single newline becomes a `<br>` the way it does in a GitHub comment box. Turn it off for Markdown that’s hard-wrapped and meant to reflow. Fields saved with the old flavour name carry over on their own.
- The `|marky` filter, the `wahlbergEditor()` function and the field-less Preview endpoint now default to preserving line breaks too, matching the field.
- **Minimum Rows** and **Maximum Rows** can now go down to 1, for a field that looks like a one-liner and grows only if it has to. They were floored at 3.
- New fields now default to a 2-row minimum rather than 12. `Editor::inputHtml()` and the Twig macros fall back to the same value, so an editor another plugin renders matches one in a field layout. Existing fields keep whatever they were saved with.
- Fixed the Preview tab’s loading spinner pushing the box taller than the editor it replaced, then dropping it shut again once the preview arrived. Most visible on a one-row field, where the spinner is taller than the text.
- Fixed the editor never rendering shorter than about three and a half lines whatever **Minimum Rows** was set to. A fixed `min-height` in the stylesheet was outranking the height the editor sizes itself to, so the floor now follows the setting.

- Craft’s reference tags, `{entry:123:url}` and `{asset:5:title}` and the rest, are now resolved when a Markdown field renders, so a link written as `[Read more]({entry:123:url})` survives a slug change. Tags inside code fences and inline code spans are left as the author typed them.
- Added a **Parse Reference Tags** field setting, on by default.
- Added a `|marky` filter, for parsing Markdown that doesn’t live in a Markdown field the way the field would: reference tags resolved, HTML purified. Craft’s `|md` is unaffected and still there for plain parsing.

- The Preview tab now renders Tabler Icons’ `{icon:star}` tokens as icons when that plugin is installed, leaving any inside code as typed. The field’s stored value is unchanged, so templates still pipe through `|tabler` themselves, as the README explains. Nothing happens when the plugin isn’t installed or is disabled.
- Headings and bold text now render bold in the Write tab, and italics italic. The editor measures the bold and italic faces against the regular one on load and only uses them where they advance identically, so a font family without real cuts falls back to colour alone rather than drifting the caret.
- The editor’s surfaces and syntax colours now come from Craft’s own palette rather than fixed hex values, so the field follows a control panel theme’s dark mode instead of staying a white pane with near-black text on it.
- Fixed the selected tab’s border rendering a shade lighter than the header’s bottom border beside it.
- Fixed the highlighted layer’s alignment correction being discarded once a webfont finished loading, which could leave the text drifting out from under the caret.

- The editor can now be used outside the field type: `{% import 'wahlberg/editor' as wahlberg %}` gives you `wahlberg.field()` and `wahlberg.input()` macros, and `Editor::inputHtml()` does the same from PHP. Pass `highlight: false` for a plain textarea with the same chrome.

- The Write tab now highlights Markdown syntax: headings, emphasis, code, links, quotes and list markers. It works by way of a highlighted layer sitting behind the textarea, so native undo, spellcheck and text selection all still work.
- The **Preview** tab is disabled until something has been written.
- Added a **Text Size** field setting for the editor, 11–20px, defaulting to 14px.
- Made the tabs fill the header height so they don’t float in the middle of it.

- Added a **Purify HTML** field setting, on by default. The parsed HTML is run through HTML Purifier using Craft’s own defaults, so YouTube and Vimeo embeds survive and inline `<script>` doesn’t. The raw Markdown is never modified.
- Added an **HTML Purifier Config** field setting, for pointing at a JSON config file in `config/htmlpurifier/`.
- Added a **Show Preview Tab** field setting, on by default. With it off, the editor is source-only and the formatting toolbar moves to where the tabs were.
- Added a **Maximum Rows** field setting, for capping how tall the editor can grow.
- The editor now grows to fit what’s being typed. Dragging the resize handle hands control back to the author.
- The formatting toolbar now folds into a menu when the field is too narrow to show every button.
- Renamed the **Initial Rows** field setting to **Minimum Rows**. Existing fields carry their value over.

## 1.0.0 - 2026-07-25

- Initial release.
