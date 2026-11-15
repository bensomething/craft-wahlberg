<?php

declare(strict_types=1);

namespace bensomething\wahlberg\Markdown;

use bensomething\wahlberg\Markdown\Alerts\AlertExtension;
use CraftCms\Cms\Markdown\CommonMark\Extensions\NoIndentedCodeExtension;
use CraftCms\Cms\Markdown\CommonMark\Extensions\UserMentionExtension;
use CraftCms\Cms\Markdown\Flavors\Flavor;
use CraftCms\Cms\Markdown\MarkdownOptions;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Footnote\FootnoteExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkProcessor;
use League\CommonMark\Extension\Highlight\HighlightExtension;
use League\CommonMark\Extension\InlinesOnly\InlinesOnlyExtension;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Extension\TaskList\TaskListExtension;
use League\CommonMark\MarkdownConverter;

/**
 * The Wahlberg flavour: everything Craft's GitHub-Flavored Markdown does, plus
 *
 * - GitHub's alerts: a quote opening `[!NOTE]`, `[!TIP]`, `[!IMPORTANT]`,
 *   `[!WARNING]` or `[!CAUTION]`, rendered with GitHub's own classes.
 * - Footnotes: `text[^1]` and `[^1]: the note`.
 * - Highlights: `==marked==` becomes `<mark>`.
 * - An `id` on every heading, from its text, so a page can link to a section.
 *
 * GFM is built here rather than by wrapping Craft's GfmFlavor: the heading ids
 * need config, and league/commonmark only takes config when the environment is
 * created.
 */
final class WahlbergFlavor extends Flavor
{
    /**
     * What the field's flavour menu shows too, since Craft labels flavours it doesn't
     * know by their name.
     */
    public const string NAME = 'Wahlberg';

    public function __invoke(MarkdownOptions $options): MarkdownConverter
    {
        $config = [];

        // Only alongside the extension that reads it: league/commonmark rejects config
        // for an extension that isn't there, which inline-only parsing leaves out
        if (! $options->inlineOnly) {
            $config['heading_permalink'] = [
                'insert' => HeadingPermalinkProcessor::INSERT_NONE,
                'apply_id_to_heading' => true,
                'id_prefix' => '',
                'fragment_prefix' => '',
            ];
        }

        if (! $options->allowUnsafeLinks) {
            $config['allow_unsafe_links'] = false;
        }

        $environment = new Environment($config);
        $environment->addExtension(new UserMentionExtension);

        if ($options->inlineOnly) {
            $environment
                ->addExtension(new InlinesOnlyExtension)
                ->addExtension(new StrikethroughExtension)
                ->addExtension(new HighlightExtension);

            return new MarkdownConverter($environment);
        }

        $environment->addExtension(new CommonMarkCoreExtension);

        if (! $options->indentedCode) {
            $environment->addExtension(new NoIndentedCodeExtension);
        }

        $environment
            // Craft's GFM
            ->addExtension(new AutolinkExtension)
            ->addExtension(new StrikethroughExtension)
            ->addExtension(new TableExtension)
            ->addExtension(new TaskListExtension)
            // Wahlberg's
            ->addExtension(new AlertExtension)
            ->addExtension(new FootnoteExtension)
            ->addExtension(new HighlightExtension)
            ->addExtension(new HeadingPermalinkExtension);

        return new MarkdownConverter($environment);
    }
}
