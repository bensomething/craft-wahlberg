# Changelog

## Unreleased

### Added

- Added a **Floating Toolbar** setting, which puts the formatting buttons in a panel over the selection rather than in a strip above the editor.
- Added `⌘⇧F`, which brings the floating toolbar up at the caret with nothing selected, and `Esc`, which puts it away.
- Added `⌘⇧E` and `⌘⇧U` for the **Entry** and **Asset** pickers.
- Added a `floating` option to `Editor::inputHtml()` and the Twig macros.

### Changed

- The read-only field shows the disabled input cursor on hover.
- Regrouped the field settings: **Editor** for the sizing and the field limit, **Toolbar** for everything above the writing surface.

### Fixed

- Fixed a bug where a field flashed empty on load before its content appeared. The editor now shows the textarea’s own text until the layer behind it is ready to take over.
- Fixed a bug where a field opened at its minimum rows and grew a paint later. It now opens at the height its content needs, and browsers without `field-sizing` keep the old behaviour.
- The **Markdown guide** button no longer takes the selection when it’s clicked, as every other button on the toolbar already didn’t.

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
