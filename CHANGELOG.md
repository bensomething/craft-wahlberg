# Changelog

## 1.0.0-beta.3 - 2026-07-28

- Added `⌘⇧P`, which swaps between the **Write** and **Preview** tabs from anywhere in the field.
- The line being written now carries a band behind it, over every row a wrapped line takes. `--wahlberg-active-line` and `--wahlberg-active-line-pad` retheme it, `transparent` removes it.
- The editor’s line height can now be set with `--wahlberg-line-height`.

## 1.0.0-beta.2 - 2026-07-27

- The **Write** and **Preview** tabs have slightly less rounded top corners, so they sit inside the field’s own corner rather than matching it.
- The **Markdown guide** button uses a plain question mark rather than one in a circle.
- The **Markdown guide** button is now off by default, alongside the numbered headings. Fields already saved keep it.
- Removed the **Task list** button, and the task list row from the Markdown guide.

## 1.0.0-beta.1 - 2026-07-27

First public release.

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
