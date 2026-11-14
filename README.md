# Wahlberg for Craft CMS

Snippets for Craft’s Markdown field.

> [!NOTE]
> This is the Craft 6 line, in development. Craft 6 ships a Markdown field of its own, with a toolbar, a server-rendered preview and reference tags, so Wahlberg 2 doesn’t bring a field type. It adds the snippets from Wahlberg 1 to the native field instead.
>
> For Craft 5, use [Wahlberg 1.x](https://github.com/bensomething/craft-wahlberg/tree/develop), the full field.

## Requirements

- Craft CMS 6
- PHP 8.5+

## Snippets

Set snippets up under **Settings → Plugins → Wahlberg**. Each has a label, a handle, its Markdown, and optionally the handles of the fields that offer it. Without any, every Markdown field does.

In a Markdown field, type `/` at the start of a line or after a space to open the snippets menu, and keep typing to filter it.

In a snippet’s Markdown:

- `$1` to `$9` are stops, visited in order on Tab and ⇧Tab. Esc ends the run.
- `${1:like this}` is a stop with a default: the text goes in, and landing on the stop selects it.
- `$0` is where the caret ends up, after any numbered stops. Without one it lands at the end.

## Configuration

To fix snippets or the trigger in code, publish the config file:

```bash
php artisan vendor:publish --tag=wahlberg
```

It lands at `config/craft/wahlberg.php`. A key set there replaces the saved setting and shows read-only on the settings screen. See the comments in it for the format.

## Extending

Plugins and modules can add snippets of their own by listening for `bensomething\wahlberg\Events\SnippetsRegistering` and adding to `$event->snippets`, in the config file’s format. A handle the installation defines wins.
