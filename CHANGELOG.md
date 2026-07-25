# Changelog

## Unreleased

- The editor can now be used outside the field type: `{% import 'wahlberg/editor' as wahlberg %}` gives you `wahlberg.field()` and `wahlberg.input()` macros, and `Editor::inputHtml()` does the same from PHP. Pass `highlight: false` for a plain textarea with the same chrome.

- The Write tab now highlights Markdown syntax — headings, emphasis, code, links, quotes and list markers — by way of a highlighted layer sitting behind the textarea, so native undo, spellcheck and text selection all still work.
- The **Preview** tab is disabled until something has been written.
- Added a **Text Size** field setting for the editor, 11–20px, defaulting to 15px.
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
