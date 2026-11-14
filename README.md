# Wahlberg for Craft CMS

Snippets for Craft’s Markdown field.

> [!NOTE]
> This is the Craft 6 line, in development. Craft 6 ships a Markdown field of its own, with a toolbar, a server-rendered preview and reference tags, so Wahlberg 2 doesn’t bring a field type. It adds the snippets from Wahlberg 1 to the native field instead.
>
> For Craft 5, use [Wahlberg 1.x](https://github.com/bensomething/craft-wahlberg/tree/develop), the full field.

## Requirements

- Craft CMS 6
- PHP 8.5+

## Configuration

Publish the config file:

```bash
php artisan vendor:publish --tag=wahlberg
```

It lands at `config/craft/wahlberg.php`. See the comments in it for the snippet format.
