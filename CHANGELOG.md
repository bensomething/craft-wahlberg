# Changelog

## Unreleased

- Added **snippets**: blocks of Markdown authors can drop in from a new toolbar button, defined in `config/wahlberg.php`. `$0` marks where the caret lands and `$SELECTION` is replaced by whatever was selected, so a snippet can wrap an author’s own text. Each takes an optional `icon`. Copy `src/config.php` to start from a working example. A new **Available Snippets** field setting picks which of them a given field offers; with no config file the button and the setting both hide themselves.
- Plugins can add snippets of their own through `Snippets::EVENT_REGISTER_SNIPPETS`, or pass definitions straight to `Editor::inputHtml()`. A handle defined in `config/wahlberg.php` takes precedence over one a plugin registers.
- Added an **Inline Only** field setting, off by default, for rendering a value without the paragraph wrapped around it.
- Added an **Encode HTML** field setting, off by default. Encodes HTML before parsing, so a tag an author types shows up as text rather than as markup — stricter than purifying, which parses the HTML first and then drops what isn’t safe. Enabling it forces Craft’s `pre-encoded` parser, which is what `.flavour` then reports.
- Added a **Field Limit** field setting, in characters or bytes, enforced on save. It counts the Markdown an author types rather than the HTML it renders to.
- Added a **Show Stats** field setting, off by default: character, word and line counts under the editor, with the field limit alongside them when there is one.
- Added a **Placeholder Text** field setting.
- Added a **Toolbar Buttons** field setting, for choosing which formatting buttons a field offers. The toolbar keeps its own order and grouping however many are turned off.
- Added **Strikethrough**, **Task list**, **Heading 1**–**Heading 6**, **Entry**, **Asset** and **Markdown guide** toolbar buttons.
- The **Heading** button is now driven by which levels are turned on, and there’s only ever one of it: none ticked hides it, one applies that level outright, and two or more open a dropdown. It keeps the same plain **H** icon throughout, with the level in the tooltip. New fields start with **Heading 2** alone, since level 1 is usually the element’s own title. This replaces the old button that cycled to `###`.
- The **Entry** and **Asset** buttons open Craft’s element selector. **Asset** writes an image as `![alt](…)` and anything else as a link, taking the alt text from the asset. With **Parse Reference Tags** on both write `{entry:19:url}` or `{asset:41:url}` rather than a URL, so the link survives a slug change or follows a file that’s replaced.
- Added **Available Volumes**, **Show unpermitted volumes** and **Show unpermitted files** field settings, which apply to the **Asset** button.
- The **Markdown guide** button opens a syntax cheatsheet in a popover, the same one Craft opens off a field’s info icon. Each example is a copy-to-clipboard chip.
- The Preview tab no longer holds the height the editor had grown to. Rendered Markdown is nearly always shorter than its source, so a long document left a large empty panel below the preview.
- The toolbar now separates the block, link and list buttons into groups of their own, with slimmer dividers between them.
- The field settings screen is now grouped under **Appearance**, **Parsing** and **Assets** headings.
- Images in the Preview tab are now capped at the width of the preview and 320px tall. A full-size image was rendering at its natural dimensions and pushing everything else, tabs included, off the screen.
- The Write/Preview tabs now sit a little tighter to the edge when the formatting toolbar is turned off.
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
