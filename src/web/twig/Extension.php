<?php

namespace bensomething\wahlberg\web\twig;

use bensomething\wahlberg\Editor;
use bensomething\wahlberg\fields\MarkdownField;
use bensomething\wahlberg\models\MarkdownData;
use craft\helpers\Template;
use Twig\Extension\AbstractExtension;
use Twig\Markup;
use Twig\TwigFilter;
use Twig\TwigFunction;

class Extension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('marky', [$this, 'marky'], ['is_safe' => ['html']]),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('wahlbergEditor', [$this, 'editor'], ['is_safe' => ['html']]),
        ];
    }

    /**
     * Parses Markdown the way a Markdown field does, reference tags resolved and
     * HTML purified, for content that doesn’t live in one.
     *
     * ```twig
     * {{ entry.summary|marky }}
     * {{ entry.summary|marky(flavour='original', purify=false) }}
     * ```
     *
     * Craft’s own `|md` is untouched and still there for anyone who wants plain
     * Markdown parsing and nothing else.
     *
     * Passing a Markdown field’s value takes that field’s settings as the defaults,
     * so `{{ entry.body|marky }}` and `{{ entry.body.html }}` agree, and any
     * argument given here overrides just that one setting.
     */
    public function marky(
        mixed $value,
        ?string $flavour = null,
        ?bool $refs = null,
        ?bool $purify = null,
        ?string $purifierConfig = null,
        ?int $siteId = null,
    ): Markup {
        $defaults = $value instanceof MarkdownData ? $value : null;

        // Nothing to override, so hand back the field’s own parse rather than
        // repeating it with a fresh object
        if ($defaults !== null && $flavour === null && $refs === null && $purify === null && $purifierConfig === null && $siteId === null) {
            return $defaults->getHtml();
        }

        $markdown = (string)$value;

        if (trim($markdown) === '') {
            return Template::raw('');
        }

        return (new MarkdownData(
            $markdown,
            $flavour ?? $defaults?->getFlavour() ?? MarkdownField::FLAVOUR_GFM_COMMENT,
            $purify ?? $defaults?->getPurified() ?? true,
            $purifierConfig ?? $defaults?->getPurifierConfig(),
            $refs ?? $defaults?->getParseRefs() ?? true,
            $siteId ?? $defaults?->getSiteId(),
        ))->getHtml();
    }

    /**
     * Renders the Markdown editor. See [[Editor::inputHtml()]] for the options.
     */
    public function editor(array $config = []): Markup
    {
        return Template::raw(Editor::inputHtml($config));
    }
}
