# Changelog

## Unreleased

- The **Write** and **Preview** tabs have slightly less rounded top corners, so they sit inside the field’s own corner rather than matching it.
- The **Markdown guide** button uses a plain question mark rather than one in a circle, so it sits with the other toolbar icons.

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
