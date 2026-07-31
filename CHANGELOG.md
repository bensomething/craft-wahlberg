# Changelog

## Unreleased

### Added

- Added a **Floating Toolbar** setting, which puts the formatting buttons in a panel over the selection rather than in a strip above the editor.
- Added a **Line Length** setting, which holds the text to a readable measure rather than the full width of the field. `--wahlberg-measure` sets the width.
- Added a **New Paragraph on Enter** setting, which has Enter leave a blank line behind it. `⇧Enter` still gives the single newline.
- Added a `/` menu at the caret, listing the **Entry** and **Asset** pickers and then the field’s snippets, filtered as you type.
- Added `$1` to `$9` snippet stops, visited on `Tab` and `⇧Tab`, and `${1:defaults}`. A body carrying only `$0` behaves as it always did.
- Added `⌘⇧F` for the floating toolbar, and `⌘⇧E` and `⌘⇧U` for the **Entry** and **Asset** pickers.
- Added a `floating` option to `Editor::inputHtml()` and the Twig macros.
- The tabs and the toolbar now stick to the top of a field that fills more than half the window. `--wahlberg-sticky-top` overrules where they stop.
- Switching between **Write** and **Preview** now keeps the author’s place.

### Changed

- Regrouped the field settings: **Editor** for the sizing and the field limit, **Toolbar** for everything above the writing surface.
- **Show Syntax Highlighting** moved to the bottom of the **Editor** settings.
- `Editor::snippetsMenuHtml()` is now `Editor::insertMenuHtml()`. The **Snippets** button and `⌘⇧K` still open snippets alone.
- The stats bar reads `1,234 words` rather than `1234 words`.
- The read-only field shows the disabled input cursor on hover.

### Fixed

- Fixed a bug where a field flashed empty on load before its content appeared.
- Fixed a bug where a field opened at its minimum rows and grew a paint later. Browsers without `field-sizing` keep the old behaviour.
- Fixed the top corners of a field with no header and a stats bar, where the writing surface painted square corners over the field’s rounded ones.
- The **Markdown guide** button no longer takes the selection when it’s clicked.

## 1.0.0-beta.4 - 2026-07-29

### Added

- Added `Editor::staticHtml()`, which is that rendering, for anywhere else a value is being shown rather than edited.

### Fixed

- Fixed a bug where a Markdown field showed nothing when it was rendered read-only (like when viewing a revision). The field now has a static rendering of its own.

## 1.0.0-beta.3 - 2026-07-28

### Added

- Added `⌘⇧P`, which swaps between the **Write** and **Preview** tabs from anywhere in the field.
- The line being written now carries a band behind it, over every row a wrapped line takes. `--wahlberg-active-line` and `--wahlberg-active-line-pad` retheme it, `transparent` removes it.
- The editor’s line height can now be set with `--wahlberg-line-height`.

## 1.0.0-beta.2 - 2026-07-27

### Changed

- The **Write** and **Preview** tabs have slightly less rounded top corners, so they sit inside the field’s own corner rather than matching it.
- The **Markdown guide** button uses a plain question mark rather than one in a circle.
- The **Markdown guide** button is now off by default, alongside the numbered headings. Fields already saved keep it.

### Removed

- Removed the **Task list** button, and the task list row from the Markdown guide.

## 1.0.0-beta.1 - 2026-07-27

First public release.

### Added

- Markdown field with a **Write** tab, a server-rendered **Preview** tab, and a formatting toolbar
- Markdown syntax highlighting in the Write tab, without giving up the plain textarea
- Craft reference tags resolved on output, and left alone inside code
- HTML Purifier on the parsed output by default, with per-field config files, plus an **Encode HTML** option for fields that shouldn’t take markup at all
- Entry and asset pickers that write reference tags, so links survive a slug change or a replaced file
- Snippets: blocks of Markdown defined in `config/wahlberg.php`, dropped in from the toolbar or with `⌘⇧K`, which opens the list at the caret
- Per-field control over the flavour, toolbar buttons, sizing, placeholder, character or byte limit, and counts
- The editor is usable outside the field type, from Twig or PHP
- GraphQL support

See the [README](README.md) for the whole of it.
