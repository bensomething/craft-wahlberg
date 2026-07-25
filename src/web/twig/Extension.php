<?php

namespace bensomething\wahlberg\web\twig;

use bensomething\wahlberg\Editor;
use craft\helpers\Template;
use Twig\Extension\AbstractExtension;
use Twig\Markup;
use Twig\TwigFunction;

class Extension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('wahlbergEditor', [$this, 'editor'], ['is_safe' => ['html']]),
        ];
    }

    /**
     * Renders the Markdown editor. See [[Editor::inputHtml()]] for the options.
     */
    public function editor(array $config = []): Markup
    {
        return Template::raw(Editor::inputHtml($config));
    }
}
