/* global Craft, Garnish */
(function() {
    'use strict';

    const IS_MAC = /Mac|iP(hone|ad|od)/.test(navigator.platform || '');
    const MOD_KEY = IS_MAC ? 'metaKey' : 'ctrlKey';
    const MOD_LABEL = IS_MAC ? '⌘' : 'Ctrl+';
    const MOD_SHIFT_LABEL = IS_MAC ? '⌘⇧' : 'Ctrl+Shift+';

    // A list item, split into indent / marker / spacing / content
    const LIST_ITEM = /^(\s*)([-*+]|\d+[.)])(\s+)(.*)$/;
    const BLOCKQUOTE = /^(\s*>\s?)(.*)$/;

    // Line-prefixing commands: the pattern strips an existing prefix (which is
    // also how we detect a toggle-off), the prefix function builds a new one.
    // `test` is what counts as already-applied when that's looser than the prefix
    // the command builds: a ticked task is still a task.
    const PREFIXES = {
        quote: {pattern: /^ {0,3}> ?/, prefix: () => '> '},
        // Strips the task box too, so lists toggle each other rather than stacking
        ul: {pattern: /^ {0,3}[-*+] +(?:\[[ xX]\] +)?/, prefix: () => '- '},
        ol: {pattern: /^ {0,3}\d+[.)] +/, prefix: (i) => (i + 1) + '. '},
        tasklist: {
            pattern: /^ {0,3}[-*+] +(?:\[[ xX]\] +)?/,
            prefix: () => '- [ ] ',
            test: /^ {0,3}[-*+] +\[[ xX]\] +/,
        },
    };

    // No `test`: exact, so H3 on an H1 line makes it an H3 rather than clearing it
    for (let level = 1; level <= 6; level++) {
        PREFIXES['h' + level] = {
            pattern: /^#{1,6} +/,
            prefix: () => '#'.repeat(level) + ' ',
        };
    }

    // -- Syntax highlighting --------------------------------------------------
    //
    // The one rule here: only ever wrap text in spans. The highlighted layer has
    // to hold character-for-character the same text as the textarea, or the two
    // stop lining up.

    const ESCAPES = {'&': '&amp;', '<': '&lt;', '>': '&gt;'};

    function esc(text) {
        return text.replace(/[&<>]/g, (char) => ESCAPES[char]);
    }

    function span(className, text) {
        return '<span class="' + className + '">' + esc(text) + '</span>';
    }

    function wrapped(className, open, content, close) {
        return '<span class="' + className + '">' + span('wh-mark', open) + esc(content) +
            span('wh-mark', close) + '</span>';
    }

    // Sticky, so they can be tested at a position without slicing the string
    const INLINE_RULES = [
        {name: 'code', re: /(`+)[\s\S]*?\1/y},
        {name: 'link', re: /(!?)\[([^\]\n]*)\]\(([^)\n]*)\)/y},
        {name: 'strong', re: /(\*\*|__)(?=\S)([\s\S]*?\S)\1/y},
        {name: 'em', re: /([*_])(?=\S)([^\n]*?\S)\1/y},
        {name: 'del', re: /~~(?=\S)([\s\S]*?\S)~~/y},
        {name: 'autolink', re: /<[a-z][\w+.-]*:[^>\s]*>/iy},
        {name: 'url', re: /https?:\/\/[^\s<)\]]+/iy},
    ];

    const TRIGGERS = '`![*_~<h';

    function renderInlineToken(name, match) {
        switch (name) {
            case 'code':
                return span('wh-code', match[0]);
            case 'link': {
                const open = match[1] + '[';
                return '<span class="wh-link">' + span('wh-mark', open) + esc(match[2]) +
                    span('wh-mark', '](') + span('wh-url', match[3]) + span('wh-mark', ')') + '</span>';
            }
            case 'strong':
                return wrapped('wh-strong', match[1], match[2], match[1]);
            case 'em':
                return wrapped('wh-em', match[1], match[2], match[1]);
            case 'del':
                return wrapped('wh-del', '~~', match[1], '~~');
            default:
                return span('wh-url', match[0]);
        }
    }

    function inline(text) {
        let out = '';
        let plain = '';
        let i = 0;

        while (i < text.length) {
            let token = null;

            if (TRIGGERS.indexOf(text[i]) !== -1) {
                for (const rule of INLINE_RULES) {
                    // Don't let snake_case words read as emphasis
                    if (rule.name === 'em' && text[i] === '_' && i > 0 && /\w/.test(text[i - 1])) {
                        continue;
                    }

                    rule.re.lastIndex = i;
                    const match = rule.re.exec(text);

                    if (match) {
                        token = renderInlineToken(rule.name, match);
                        i += match[0].length;
                        break;
                    }
                }
            }

            if (token === null) {
                plain += text[i];
                i++;
                continue;
            }

            out += esc(plain) + token;
            plain = '';
        }

        return out + esc(plain);
    }

    const FENCE = /^(\s*)(```+|~~~+)(.*)$/;
    const HEADING = /^(\s*#{1,6}\s+)([\s\S]*)$/;
    const RULE = /^\s*(?:(?:\*\s*){3,}|(?:-\s*){3,}|(?:_\s*){3,})$/;
    const QUOTE = /^(\s*>+\s?)([\s\S]*)$/;
    const LIST = /^(\s*)([-*+]|\d+[.)])(\s+)([\s\S]*)$/;

    /**
     * Swaps trailing spaces for non-breaking ones.
     *
     * `white-space: pre-wrap` lets spaces at the end of a line hang, so they take
     * up no width, but the textarea's caret still advances past them and the two
     * layers part company by exactly that many characters. A non-breaking space
     * can't hang, and in a monospace font it's the same width as a space.
     */
    function protectTrailing(line) {
        return line.replace(/ +$/, (spaces) => '\u00a0'.repeat(spaces.length));
    }

    function highlightMarkdown(value) {
        const lines = value.split('\n').map(protectTrailing);
        const out = [];
        let fence = null;

        for (const line of lines) {
            const fenced = line.match(FENCE);

            if (fence) {
                out.push(span('wh-fence', line));

                if (fenced && fenced[2][0] === fence[0] && fenced[2].length >= fence.length) {
                    fence = null;
                }

                continue;
            }

            if (fenced) {
                fence = fenced[2];
                out.push(span('wh-fence', line));
                continue;
            }

            if (RULE.test(line)) {
                out.push(span('wh-mark', line));
                continue;
            }

            const heading = line.match(HEADING);

            if (heading) {
                out.push('<span class="wh-heading">' + span('wh-mark', heading[1]) + inline(heading[2]) + '</span>');
                continue;
            }

            const quote = line.match(QUOTE);

            if (quote) {
                out.push('<span class="wh-quote">' + span('wh-mark', quote[1]) + inline(quote[2]) + '</span>');
                continue;
            }

            const list = line.match(LIST);

            if (list) {
                out.push(esc(list[1]) + span('wh-mark', list[2]) + esc(list[3]) + inline(list[4]));
                continue;
            }

            out.push(inline(line));
        }

        // Trailing newline, so the last line keeps its height in the <pre>
        return out.join('\n') + '\n';
    }

    // Copied onto the mirror that finds the caret, so it wraps the same text at the
    // same width in the same face
    const CARET_STYLES = [
        'fontFamily', 'fontSize', 'fontStyle', 'fontWeight', 'fontVariant', 'fontStretch',
        'letterSpacing', 'wordSpacing', 'lineHeight', 'textTransform', 'textIndent',
        'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft',
        'borderTopWidth', 'borderRightWidth', 'borderBottomWidth', 'borderLeftWidth',
        'boxSizing', 'tabSize', 'fontKerning', 'fontVariantLigatures', 'fontFeatureSettings',
        'textRendering',
    ];

    // Copied onto the probe so it renders the sample exactly as the real element would
    const METRIC_STYLES = [
        'fontFamily', 'fontSize', 'fontStyle', 'fontWeight', 'fontStretch', 'fontVariant',
        'fontFeatureSettings', 'fontVariantLigatures', 'fontKerning', 'fontOpticalSizing',
        'letterSpacing', 'wordSpacing', 'textRendering', 'textTransform', 'webkitFontSmoothing',
    ];

    /**
     * The width of one character as the given element would render it, measured
     * on an off-screen copy so nothing on screen has to be disturbed.
     *
     * `overrides` applies styles the element doesn't carry itself, for asking what
     * a character *would* advance to in another face.
     */
    function measureAdvance(element, isTextarea, sample, overrides) {
        const styles = window.getComputedStyle(element);
        const probe = document.createElement(isTextarea ? 'textarea' : 'div');

        if (isTextarea) {
            probe.setAttribute('wrap', 'off');
            probe.rows = 1;
        }

        probe.style.cssText = 'position:absolute;top:-9999px;left:0;width:50px;height:20px;' +
            'margin:0;padding:0;border:0;overflow:auto;white-space:pre;resize:none;box-sizing:content-box;';

        METRIC_STYLES.forEach((property) => {
            if (styles[property]) {
                probe.style[property] = styles[property];
            }
        });

        if (overrides) {
            Object.keys(overrides).forEach((property) => {
                probe.style[property] = overrides[property];
            });
        }

        if (isTextarea) {
            probe.value = sample;
        } else {
            probe.textContent = sample;
        }

        document.body.appendChild(probe);
        const width = probe.scrollWidth;
        probe.remove();

        return width ? width / sample.length : 0;
    }

    class WahlbergEditor {
        constructor(selector, config) {
            this.container = document.querySelector(selector);

            if (!this.container || this.container.dataset.wahlbergInitialized) {
                return;
            }

            this.container.dataset.wahlbergInitialized = '1';

            this.config = config || {};
            this.source = this.container.querySelector('[data-source]');
            this.editorEl = this.container.querySelector('[data-editor]');
            this.highlight = this.container.querySelector('[data-highlight]');
            this.previewEl = this.container.querySelector('[data-preview]');
            this.tabs = Array.from(this.container.querySelectorAll('[data-tab]'));
            this.previewTab = this.container.querySelector('[data-tab="preview"]');

            this.toolbar = this.container.querySelector('[data-toolbar]');
            this.groups = Array.from(this.container.querySelectorAll('[data-toolbar-group]'));
            this.buttons = Array.from(this.container.querySelectorAll('[data-toolbar-group] [data-command]'));
            this.overflow = this.container.querySelector('[data-overflow]');
            this.headingsEl = this.container.querySelector('[data-headings]');
            this.guideBtn = this.container.querySelector('[data-guide-trigger]');
            this.guideBody = this.container.querySelector('[data-guide]');
            this.stats = this.container.querySelector('[data-stats]');

            // The Markdown the preview currently shows, so switching tabs back
            // and forth doesn't re-request an unchanged document
            this.previewed = null;

            // Once the author drags the editor to a height they want, stop
            // second-guessing them
            this.manuallySized = false;

            this.tabs.forEach((tab) => {
                tab.addEventListener('click', () => this.showTab(tab.dataset.tab));
                tab.addEventListener('keydown', (event) => this.onTabKeydown(event));
            });

            this.buttons.forEach((button) => {
                const shortcut = button.dataset.shortcut;
                if (shortcut) {
                    button.title += ' (' + MOD_LABEL + shortcut + ')';
                }
                // Keep the textarea's selection when a button takes the click
                button.addEventListener('mousedown', (event) => event.preventDefault());
                button.addEventListener('click', () => this.run(button.dataset.command));
            });

            this.source.addEventListener('keydown', (event) => this.onKeydown(event));
            this.source.addEventListener('input', () => this.onInput());
            this.source.addEventListener('scroll', () => this.syncScroll());

            this.initOverflow();
            this.initHeadings();
            this.initSnippets();
            this.initGuide();
            this.initSizing();
            this.alignMetrics();
            this.alignFaces();
            this.renderHighlight();
            this.renderStats();
            this.syncPreviewTab();

            // Webfonts can land after this runs and change the metrics under us,
            // including whether the family has a real bold and italic at all
            if (document.fonts && document.fonts.ready) {
                document.fonts.ready.then(() => {
                    this.alignMetrics();
                    this.alignFaces();
                });
            }
        }

        onInput() {
            this.autoGrow();
            this.renderHighlight();
            this.renderStats();
            this.syncPreviewTab();
        }

        // -- Highlighting -----------------------------------------------------

        /**
         * Measures how wide a character actually renders in each layer and, if
         * they disagree, nudges the highlighted layer's letter-spacing until
         * they don't.
         *
         * They're styled identically, but a textarea and a <pre> don't always
         * resolve the same face out of the same font stack, and a fraction of a
         * pixel per character is all it takes for the text behind the caret to
         * visibly slide out from under it.
         */
        alignMetrics() {
            if (!this.highlight) {
                return;
            }

            const sample = 'M'.repeat(200);
            const source = measureAdvance(this.source, true, sample);
            const layer = measureAdvance(this.highlight, false, sample);

            if (!source || !layer) {
                return;
            }

            // The probe copies letter-spacing across with the rest of the metrics, so
            // the layer was measured through whatever correction is already on it.
            // What comes out is therefore the drift still left, not the whole of it,
            // which is what makes a second run safe: a face that now matches reads as
            // no drift, and the correction already in place has to stay where it is
            // rather than be cleared out from under the caret.
            const applied = parseFloat(this.highlight.style.letterSpacing) || 0;
            const drift = source - layer;

            // Leave it alone unless the drift would show up within a line or two
            if (Math.abs(drift) > 0.005) {
                const spacing = applied + drift;

                // Back to no correction at all, rather than a rounded-off 0px
                this.highlight.style.letterSpacing = Math.abs(spacing) > 0.005
                    ? spacing.toFixed(4) + 'px'
                    : '';
            }

            // Handy when something still looks off: compare these two in devtools
            this.highlight.dataset.advance = source.toFixed(4) + '/' + layer.toFixed(4);
        }

        /**
         * Decides whether the tokens can carry weight and slope as well as colour.
         *
         * They can whenever the face the browser picks advances exactly as the
         * regular one does, which in a real monospace family it will, since equal
         * advances across every cut is what makes a font monospace. What can't be
         * trusted is a fabricated cut: asked for a bold the family hasn't got, the
         * browser thickens the regular one itself, and depending on the engine that
         * comes out wider. Wider means the layer creeps out from under the caret.
         *
         * So measure the face before using it, and where it doesn't hold fall back
         * to colour alone, still perfectly legible since the markers are right there
         * in the text. Measured against the layer rather than the textarea because
         * the layer is the only side that gets styled.
         */
        alignFaces() {
            if (!this.highlight) {
                return;
            }

            const sample = 'M'.repeat(200);
            const regular = measureAdvance(this.highlight, false, sample);

            if (!regular) {
                return;
            }

            // The weight the stylesheet will actually ask for, so this measures the
            // face that's going to be used and not merely a plausible one
            const bold = window.getComputedStyle(this.highlight)
                .getPropertyValue('--font-weight-bold').trim() || '700';

            // Generous next to a whole-pixel scrollWidth, tight enough that a line
            // of bold drifts by a fraction of a pixel over its full width
            const holds = (overrides) => {
                const advance = measureAdvance(this.highlight, false, sample, overrides);
                return !!advance && Math.abs(advance - regular) < 0.01;
            };

            this.container.classList.toggle('wahlberg--bold-tokens', holds({fontWeight: bold}));
            this.container.classList.toggle('wahlberg--italic-tokens', holds({fontStyle: 'italic'}));
        }

        /**
         * Repaints the layer behind the textarea. Coalesced, so holding a key
         * down doesn't queue up a repaint per keystroke.
         */
        renderHighlight() {
            if (!this.highlight || this.highlightFrame) {
                return;
            }

            this.highlightFrame = requestAnimationFrame(() => {
                this.highlightFrame = null;
                this.highlight.innerHTML = highlightMarkdown(this.source.value);
                this.syncScroll();
            });
        }

        syncScroll() {
            if (this.highlight) {
                this.highlight.scrollTop = this.source.scrollTop;
                this.highlight.scrollLeft = this.source.scrollLeft;
            }
        }

        /**
         * A scrollbar in the textarea narrows the text it wraps at, so the
         * highlighted layer has to lose the same width or the two wrap differently.
         */
        syncGutter() {
            if (!this.highlight) {
                return;
            }

            const styles = window.getComputedStyle(this.source);
            const borders = parseFloat(styles.borderLeftWidth) + parseFloat(styles.borderRightWidth);
            const gutter = Math.max(0, this.source.offsetWidth - this.source.clientWidth - borders);

            this.highlight.style.paddingInlineEnd = gutter
                ? 'calc(' + styles.paddingInlineEnd + ' + ' + gutter + 'px)'
                : '';
        }

        // -- Commands ---------------------------------------------------------

        run(command) {
            if (PREFIXES[command]) {
                const entry = PREFIXES[command];
                this.prefixLines(entry.pattern, entry.prefix, entry.test);
            } else {
                switch (command) {
                    case 'bold':
                        this.wrap('**', '**');
                        break;
                    case 'italic':
                        this.wrap('_', '_');
                        break;
                    case 'strike':
                        this.wrap('~~', '~~');
                        break;
                    case 'code':
                        this.code();
                        break;
                    case 'link':
                        this.link();
                        break;
                    case 'entry':
                        this.entry();
                        break;
                    case 'asset':
                        this.asset();
                        break;
                }
            }

            this.autoGrow();
        }

        /**
         * Wraps the selection in `before`/`after`, or unwraps it if it's already wrapped.
         */
        wrap(before, after) {
            const source = this.source;
            const value = source.value;
            let start = source.selectionStart;
            let end = source.selectionEnd;

            // Nothing selected? Take the word under the caret, so the markers
            // land around something rather than in the middle of a word
            if (start === end) {
                while (start > 0 && !/\s/.test(value[start - 1])) {
                    start--;
                }
                while (end < value.length && !/\s/.test(value[end])) {
                    end++;
                }
            }

            const selected = value.slice(start, end);

            // Already wrapped, with the markers sitting outside the selection
            if (start >= before.length &&
                value.slice(start - before.length, start) === before &&
                value.slice(end, end + after.length) === after
            ) {
                source.setSelectionRange(start - before.length, end + after.length);
                this.insert(selected, 0, selected.length);
                return;
            }

            // Already wrapped, with the markers inside the selection
            if (selected.length >= before.length + after.length &&
                selected.slice(0, before.length) === before &&
                selected.slice(-after.length) === after
            ) {
                const bare = selected.slice(before.length, selected.length - after.length);
                source.setSelectionRange(start, end);
                this.insert(bare, 0, bare.length);
                return;
            }

            source.setSelectionRange(start, end);
            this.insert(before + selected + after, before.length, before.length + selected.length);
        }

        /**
         * Adds (or removes) a prefix on every line the selection touches.
         */
        prefixLines(pattern, prefix, test) {
            const source = this.source;
            const value = source.value;
            const hadSelection = source.selectionStart !== source.selectionEnd;

            const from = value.lastIndexOf('\n', source.selectionStart - 1) + 1;
            let to = value.indexOf('\n', source.selectionEnd);
            if (to === -1) {
                to = value.length;
            }

            const caretOffset = source.selectionStart - from;
            const lines = value.slice(from, to).split('\n');

            // Compared against what the command builds, not the pattern it strips,
            // or an H3 button would clear an H1 instead of changing it
            const applied = (line, i) => (test
                ? test.test(line)
                : line === prefix(i) + line.replace(pattern, ''));

            const filled = lines.filter((line) => line.trim() !== '');
            const prefixed = filled.length > 0 &&
                lines.every((line, i) => line.trim() === '' || applied(line, i));

            const replacement = lines
                .map((line, i) => {
                    const bare = line.replace(pattern, '');
                    return prefixed ? bare : prefix(i) + bare;
                })
                .join('\n');

            source.setSelectionRange(from, to);

            if (hadSelection) {
                this.insert(replacement, 0, replacement.length);
                return;
            }

            // No selection: keep the caret where it was, shifted by however much
            // its own line grew or shrank
            const lineIndex = value.slice(from, from + caretOffset).split('\n').length - 1;
            const delta = replacement.split('\n')[lineIndex].length - lines[lineIndex].length;
            const caret = Math.max(0, caretOffset + delta);

            this.insert(replacement, caret, caret);
        }

        code() {
            const source = this.source;
            const selected = source.value.slice(source.selectionStart, source.selectionEnd);

            if (selected.indexOf('\n') !== -1) {
                this.wrap('```\n', '\n```');
            } else {
                this.wrap('`', '`');
            }
        }

        link() {
            const source = this.source;
            const selected = source.value.slice(source.selectionStart, source.selectionEnd);
            const trimmed = selected.trim();

            // A URL was selected, so put the caret where the link text goes
            if (trimmed !== '' && /^(https?:\/\/|mailto:|\/)\S*$/i.test(trimmed)) {
                this.insert('[](' + trimmed + ')', 1, 1);
                return;
            }

            const placeholder = Craft.t('wahlberg', 'url');
            const offset = selected.length + 2;

            this.insert(
                '[' + selected + '](' + placeholder + ')',
                offset + 1,
                offset + 1 + placeholder.length
            );
        }

        /**
         * Craft's element selector, with the caret put back where the modal took it
         * from.
         */
        pickElement(type, settings, onPick) {
            if (typeof Craft === 'undefined' || !Craft.createElementSelectorModal) {
                return;
            }

            const start = this.source.selectionStart;
            const end = this.source.selectionEnd;

            Craft.createElementSelectorModal(type, Object.assign({
                multiSelect: false,
                onSelect: (elements) => {
                    const element = elements && elements[0];

                    if (!element) {
                        return;
                    }

                    this.source.focus();
                    this.source.setSelectionRange(start, end);
                    onPick(element);
                    this.autoGrow();
                },
            }, settings));
        }

        /**
         * Links to an entry. With reference tags on it writes `{entry:1:url}` rather
         * than the URL, so the link survives a slug change.
         */
        entry() {
            // No site criteria: the modal has its own site menu, and a reference tag
            // resolves against whichever site the value is being rendered for anyway
            this.pickElement('craft\\elements\\Entry', {
                storageKey: 'WahlbergEditor.entry',
            }, (entry) => {
                const selected = this.source.value.slice(this.source.selectionStart, this.source.selectionEnd);
                const target = this.config.refTags ? '{entry:' + entry.id + ':url}' : (entry.url || '');
                const label = selected || entry.label || '';
                const text = '[' + label + '](' + target + ')';

                this.insert(text, label === '' ? 1 : text.length);
            });
        }

        /**
         * An image if it is one, a link if it isn't. Reference tags as {@see entry}.
         */
        asset() {
            this.pickElement('craft\\elements\\Asset', {
                storageKey: 'WahlbergEditor.asset',
                sources: this.config.assetSources || null,
                criteria: this.config.assetCriteria || {},
            }, (asset) => this.insertAsset(asset));
        }

        /**
         * The selector hands back no kind and no alt text. Both are on the chip, as
         * `data-kind`, `data-alt` and `data-filename`.
         */
        assetData(asset) {
            const el = asset.$element;
            const data = (name) => (el && el.data ? el.data(name) : null);

            return {
                kind: data('kind'),
                alt: data('alt'),
                filename: data('filename'),
            };
        }

        insertAsset(asset) {
            const selected = this.source.value.slice(this.source.selectionStart, this.source.selectionEnd);
            const target = this.config.refTags ? '{asset:' + asset.id + ':url}' : (asset.url || '');
            const info = this.assetData(asset);
            const isImage = info.kind === 'image';

            // A selection is the author naming it; failing that an image's own alt
            // text, then the title, then the filename
            const label = selected ||
                (isImage ? info.alt : '') ||
                asset.label ||
                info.filename ||
                '';

            const prefix = isImage ? '!' : '';
            const text = prefix + '[' + label + '](' + target + ')';

            // Unnamed? Caret between the brackets, so what's typed next is the label
            this.insert(text, label === '' ? prefix.length + 1 : text.length);
        }

        // -- Toolbar menus ----------------------------------------------------

        /**
         * The menu a disclosure button opens. Craft moves it out to the body on init,
         * so it's found by id rather than inside the field.
         */
        menuFor(container) {
            const trigger = container && container.querySelector('[data-disclosure-trigger]');
            const menuId = trigger && trigger.getAttribute('aria-controls');

            return menuId ? document.getElementById(menuId) : null;
        }

        disclosureFor(menu) {
            if (typeof Garnish === 'undefined' || !window.$ || !menu) {
                return null;
            }

            return window.$(menu).data('disclosureMenu') || null;
        }

        closeMenuIn(menu) {
            this.disclosureFor(menu)?.hide();
        }

        /**
         * Drives a menu from the keyboard for as long as it's open.
         *
         * Garnish has all of this already, bound to the menu container and working
         * off whatever is focused inside it. Opened from a shortcut rather than a
         * click, focus doesn't reliably land there, and everything downstream of
         * that assumption then does nothing. Rather than keep guessing at why, this
         * listens at the document and tracks the highlighted item itself, so it
         * behaves the same wherever focus actually is.
         */
        driveMenu(menu, close) {
            const items = Array.from(menu.querySelectorAll('.menu-item:not(.disabled)'));

            if (!items.length) {
                return;
            }

            let index = -1;

            const highlight = (to) => {
                index = (to + items.length) % items.length;

                items.forEach((item, i) => item.classList.toggle('is-highlighted', i === index));

                // Best effort: it's what gives Craft's focus ring, but the class
                // above is what guarantees the highlight is visible either way
                items[index].focus();
            };

            const stop = () => {
                document.removeEventListener('keydown', onKeydown, true);
                items.forEach((item) => item.classList.remove('is-highlighted'));
                this.drivenMenu = null;
            };

            const onKeydown = (event) => {
                // Closed by a click elsewhere
                if (menu.hidden) {
                    stop();
                    return;
                }

                const keys = {
                    ArrowDown: () => highlight(index + 1),
                    ArrowUp: () => highlight(index - 1),
                    Home: () => highlight(0),
                    End: () => highlight(items.length - 1),
                    Tab: () => highlight(index + (event.shiftKey ? -1 : 1)),
                };

                if (keys[event.key]) {
                    event.preventDefault();
                    event.stopPropagation();
                    keys[event.key]();
                    return;
                }

                if (event.key === 'Enter' && index !== -1) {
                    event.preventDefault();
                    event.stopPropagation();
                    stop();
                    items[index].click();
                    return;
                }

                if (event.key === 'Escape') {
                    event.preventDefault();
                    event.stopPropagation();
                    stop();
                    close();
                    this.source.focus();
                }
            };

            this.drivenMenu = menu;
            document.addEventListener('keydown', onKeydown, true);
            highlight(0);
        }

        /**
         * The heading menu, when a field offers more than one level. With one it's an
         * ordinary button and there's nothing to wire up.
         */
        initHeadings() {
            this.headingsMenu = this.menuFor(this.headingsEl);

            if (!this.headingsMenu) {
                return;
            }

            this.headingsMenu.querySelectorAll('[data-command]').forEach((item) => {
                item.addEventListener('click', () => {
                    this.run(item.dataset.command);
                    this.closeMenuIn(this.headingsMenu);
                });
            });
        }

        // -- Snippets ---------------------------------------------------------

        initSnippets() {
            this.snippetMenu = this.container.querySelector('[data-snippet-menu]');

            if (!this.snippetMenu) {
                return;
            }

            const trigger = this.container.querySelector('[data-snippets-trigger]');

            if (trigger) {
                trigger.title += ' (' + MOD_SHIFT_LABEL + 'K)';
                trigger.addEventListener('mousedown', (event) => event.preventDefault());
                // Anchored to itself when clicked, at the caret from the shortcut:
                // a click comes from the toolbar, so that's where the eye is
                trigger.addEventListener('click', () => {
                    if (this.snippetsOpen()) {
                        this.closeSnippets();
                    } else {
                        this.openSnippets(trigger);
                    }
                });
            }

            this.snippetMenu.querySelectorAll('[data-snippet]').forEach((item) => {
                item.addEventListener('mousedown', (event) => event.preventDefault());
                item.addEventListener('click', () => {
                    this.closeSnippets();
                    this.insertSnippet(item.dataset.snippet);
                });
            });

            document.addEventListener('mousedown', (event) => {
                if (this.snippetsOpen() && !this.snippetMenu.contains(event.target) &&
                    event.target !== trigger && !trigger?.contains(event.target)
                ) {
                    this.closeSnippets();
                }
            });
        }

        snippetsOpen() {
            return !!this.snippetMenu && this.snippetMenu.classList.contains('visible');
        }

        /**
         * Opens the snippet menu, at the caret or under whatever opened it.
         *
         * The caret is the default because that's where the snippet is going, and
         * because the shortcut has to work when the button isn't on the toolbar at
         * all. `visible` rather than an attribute of our own: Craft hides
         * `.menu:not(.visible)` outright, and that outranks anything the plugin's
         * own stylesheet says about display.
         */
        openSnippets(anchor) {
            if (!this.snippetMenu || this.snippetsOpen()) {
                return;
            }

            this.snippetMenu.classList.add('visible');

            const at = anchor ? this.below(anchor) : this.atCaret();
            const width = this.snippetMenu.offsetWidth;

            // Right-aligned under a toolbar button, which sits at the end of the
            // header and would otherwise push the menu off the edge
            const left = anchor ? at.right - width : at.left;
            const room = this.container.clientWidth - width;

            this.snippetMenu.style.top = at.top + 'px';
            this.snippetMenu.style.left = Math.max(0, Math.min(left, room)) + 'px';

            this.container.querySelectorAll('[data-snippets-trigger]')
                .forEach((button) => button.setAttribute('aria-expanded', 'true'));

            this.driveMenu(this.snippetMenu, () => this.closeSnippets());
        }

        closeSnippets() {
            if (!this.snippetsOpen()) {
                return;
            }

            this.snippetMenu.classList.remove('visible');

            this.container.querySelectorAll('[data-snippets-trigger]')
                .forEach((button) => button.setAttribute('aria-expanded', 'false'));
        }

        /**
         * Just below an element, in the field's own coordinates.
         */
        below(element) {
            const container = this.container.getBoundingClientRect();
            const rect = element.getBoundingClientRect();

            return {
                top: rect.bottom - container.top + 2,
                left: rect.left - container.left,
                right: rect.right - container.left,
            };
        }

        /**
         * Just below the caret, in the field's own coordinates.
         *
         * Measured on a throwaway copy of the textarea holding everything up to the
         * caret: a marker at the end of that lands exactly where the caret is, since
         * the copy wraps the same text at the same width in the same face. The same
         * trick the highlighted layer is built on, for one character rather than all
         * of them.
         */
        atCaret() {
            const source = this.source;
            const styles = window.getComputedStyle(source);
            const mirror = document.createElement('div');

            CARET_STYLES.forEach((property) => {
                mirror.style[property] = styles[property];
            });

            mirror.style.position = 'absolute';
            mirror.style.top = '0';
            mirror.style.left = '0';
            mirror.style.height = 'auto';
            mirror.style.visibility = 'hidden';
            mirror.style.whiteSpace = 'pre-wrap';
            mirror.style.overflowWrap = 'break-word';
            mirror.style.width = source.offsetWidth + 'px';

            mirror.textContent = source.value.slice(0, source.selectionStart);

            // Zero-width, so it can't wrap onto a line of its own
            const marker = document.createElement('span');
            marker.textContent = '\u200b';
            mirror.appendChild(marker);

            this.editorEl.appendChild(mirror);

            const top = marker.offsetTop - source.scrollTop + parseFloat(styles.lineHeight || 20);
            const left = marker.offsetLeft - source.scrollLeft;

            mirror.remove();

            // Out of the writing surface and into the field, which is what the menu
            // is positioned against
            const container = this.container.getBoundingClientRect();
            const editor = this.editorEl.getBoundingClientRect();

            return {
                top: Math.round(editor.top - container.top + top),
                left: Math.round(editor.left - container.left + left),
            };
        }

        /**
         * Writes a snippet in. `$SELECTION` becomes what was selected, `$0` is where
         * the caret ends up, and without one it goes to the end.
         */
        insertSnippet(handle) {
            const body = (this.config.snippets || {})[handle];

            if (typeof body !== 'string') {
                return;
            }

            const source = this.source;
            const selected = source.value.slice(source.selectionStart, source.selectionEnd);
            const selectionMarker = this.config.selection || '$SELECTION';
            const caretMarker = this.config.caret || '$0';

            // Every occurrence, so a snippet can use the selection twice
            let text = body.split(selectionMarker).join(selected);

            // Found after the selection has gone in, so the offset accounts for it
            const caret = text.indexOf(caretMarker);

            if (caret !== -1) {
                text = text.slice(0, caret) + text.slice(caret + caretMarker.length);
            }

            source.focus();
            this.insert(text, caret === -1 ? text.length : caret);
            this.autoGrow();
        }

        // -- Guide ------------------------------------------------------------

        initGuide() {
            if (!this.guideBtn || !this.guideBody) {
                return;
            }

            this.guideBtn.addEventListener('click', () => this.toggleGuide());
        }

        /**
         * The cheatsheet, in the popover a field's info icon uses. Built on first
         * use, since most authors never open it.
         */
        toggleGuide() {
            if (typeof Garnish === 'undefined' || !Garnish.HUD) {
                return;
            }

            if (this.hud) {
                // `showing` rather than a class of our own: Garnish owns the state
                if (this.hud.showing) {
                    this.hud.hide();
                } else {
                    this.hud.show();
                }

                return;
            }

            // Hidden so it doesn't flash in the toolbar before Garnish takes it
            this.guideBody.hidden = false;

            this.hud = new Garnish.HUD(this.guideBtn, this.guideBody, {
                hudClass: 'hud wahlberg-guide-hud',
                // No reason reading this should close a field's info HUD
                closeOtherHUDs: false,
            });

            this.hud.on('show', () => this.guideBtn.setAttribute('aria-expanded', 'true'));
            this.hud.on('hide', () => this.guideBtn.setAttribute('aria-expanded', 'false'));
            this.guideBtn.setAttribute('aria-expanded', 'true');
        }

        closeGuide() {
            if (this.hud && this.hud.showing) {
                this.hud.hide();
            }
        }

        // -- Stats ------------------------------------------------------------

        /**
         * Character, word and line counts, against the field's limit if it has one.
         */
        renderStats() {
            if (!this.stats) {
                return;
            }

            const value = this.source.value;
            const words = value.trim() === '' ? 0 : value.trim().split(/\s+/).length;

            // What the server counts: bytes for a byte limit, code points otherwise
            const limit = this.config.byteLimit || this.config.charLimit || null;
            const counted = this.config.byteLimit
                ? new TextEncoder().encode(value).length
                : Array.from(value).length;

            const parts = [];

            if (limit) {
                parts.push(Craft.t('wahlberg', '{n} of {limit}', {
                    n: Craft.formatNumber(counted),
                    limit: Craft.formatNumber(limit),
                }));
            } else {
                parts.push(Craft.t('wahlberg', '{n, plural, =1{1 character} other{# characters}}', {
                    n: Array.from(value).length,
                }));
            }

            parts.push(Craft.t('wahlberg', '{n, plural, =1{1 word} other{# words}}', {n: words}));
            parts.push(Craft.t('wahlberg', '{n, plural, =1{1 line} other{# lines}}', {
                n: value === '' ? 0 : value.split('\n').length,
            }));

            this.stats.textContent = parts.join(' · ');
            this.stats.classList.toggle('is-over', !!limit && counted > limit);
        }

        // -- Typing -----------------------------------------------------------

        onKeydown(event) {
            if (event[MOD_KEY] && event.shiftKey && !event.altKey &&
                event.key.toLowerCase() === 'k' && this.snippetMenu
            ) {
                event.preventDefault();
                this.openSnippets();
                return;
            }

            // Shift excluded, or the shifted shortcut above would lowercase into
            // this one and insert a link instead
            if (event[MOD_KEY] && !event.altKey && !event.shiftKey) {
                const command = {b: 'bold', i: 'italic', k: 'link'}[event.key.toLowerCase()];

                if (command) {
                    event.preventDefault();
                    this.run(command);
                    return;
                }
            }

            if (event.key === 'Enter' && !event.shiftKey && !event.altKey && !event[MOD_KEY]) {
                this.continueBlock(event);
            }
        }

        /**
         * Carries a list marker or blockquote onto the next line, the way GitHub does.
         */
        continueBlock(event) {
            const source = this.source;

            if (source.selectionStart !== source.selectionEnd) {
                return;
            }

            const value = source.value;
            const from = value.lastIndexOf('\n', source.selectionStart - 1) + 1;
            const line = value.slice(from, source.selectionStart);

            const list = line.match(LIST_ITEM);

            if (list) {
                const [, indent, marker, space, content] = list;
                event.preventDefault();

                // Enter on an empty item ends the list
                if (content.trim() === '') {
                    source.setSelectionRange(from, source.selectionStart);
                    this.insert('', 0, 0);
                    this.autoGrow();
                    return;
                }

                const numbered = marker.match(/^(\d+)([.)])$/);
                const next = numbered ? (parseInt(numbered[1], 10) + 1) + numbered[2] : marker;

                this.insert('\n' + indent + next + space);
                this.autoGrow();
                return;
            }

            const quote = line.match(BLOCKQUOTE);

            if (quote) {
                event.preventDefault();

                if (quote[2].trim() === '') {
                    source.setSelectionRange(from, source.selectionStart);
                    this.insert('', 0, 0);
                } else {
                    this.insert('\n' + quote[1]);
                }

                this.autoGrow();
            }
        }

        /**
         * Replaces the selection, going through execCommand so the browser's own
         * undo stack survives. Selection offsets are relative to the new text.
         */
        insert(text, selStart, selEnd) {
            const source = this.source;
            const start = source.selectionStart;

            source.focus();

            let handled = false;

            try {
                handled = text === ''
                    ? document.execCommand('delete')
                    : document.execCommand('insertText', false, text);
            } catch (e) {
                handled = false;
            }

            if (!handled) {
                // execCommand is gone or refused, so write directly and give up on undo
                source.setRangeText(text, source.selectionStart, source.selectionEnd, 'end');
                source.dispatchEvent(new Event('input', {bubbles: true}));
            }

            if (selStart !== undefined) {
                const end = selEnd === undefined ? selStart : selEnd;
                source.setSelectionRange(start + selStart, start + end);
            }
        }

        // -- Sizing -----------------------------------------------------------

        initSizing() {
            this.autoGrow();

            if (typeof ResizeObserver === 'undefined') {
                return;
            }

            // Two jobs: re-fit when the editor's width changes (rewrapping
            // changes the height it needs), and notice when the author has
            // dragged the resize handle so we can leave the height alone
            let lastWidth = this.source.offsetWidth;

            new ResizeObserver(() => {
                const width = this.source.offsetWidth;

                if (width !== lastWidth) {
                    lastWidth = width;
                    this.autoGrow();
                    return;
                }

                if (this.autoHeight !== undefined && Math.abs(this.source.offsetHeight - this.autoHeight) > 1) {
                    this.manuallySized = true;
                }
            }).observe(this.source);
        }

        /**
         * Grows the editor to fit what's been typed, between the field's minimum
         * and maximum rows.
         */
        autoGrow() {
            const source = this.source;

            // Nothing sensible to measure while the Preview tab is showing
            if (this.manuallySized || !source.offsetParent) {
                return;
            }

            const styles = window.getComputedStyle(source);
            const lineHeight = parseFloat(styles.lineHeight) || 20;
            const padding = parseFloat(styles.paddingTop) + parseFloat(styles.paddingBottom);
            const borders = parseFloat(styles.borderTopWidth) + parseFloat(styles.borderBottomWidth);
            const borderBox = styles.boxSizing === 'border-box';

            // scrollHeight covers the content plus padding, so what a row count
            // works out to depends on which box the height applies to
            const chrome = borderBox ? padding + borders : 0;
            const min = (this.config.minRows || 3) * lineHeight + chrome;
            const max = this.config.maxRows ? this.config.maxRows * lineHeight + chrome : Infinity;

            // The floor lives here rather than in the stylesheet: it depends on the
            // configured rows and on which box the height applies to, only knowable
            // once the CP's styles have landed. A CSS `min-height` would also
            // outrank the height set below, pinning short editors open.
            source.style.minHeight = min + 'px';

            source.style.height = 'auto';

            const needed = borderBox ? source.scrollHeight + borders : source.scrollHeight - padding;
            const height = Math.min(Math.max(needed, min), max);

            source.style.height = height + 'px';
            source.style.overflowY = needed > height ? 'auto' : 'hidden';

            this.autoHeight = source.offsetHeight;
            this.syncGutter();
        }

        // -- Toolbar overflow -------------------------------------------------

        initOverflow() {
            if (!this.toolbar || !this.overflow) {
                return;
            }

            const trigger = this.overflow.querySelector('[data-disclosure-trigger]');
            const menuId = trigger && trigger.getAttribute('aria-controls');

            // Craft moves the menu out to the body when it initializes, so find
            // it by id rather than looking inside our own container
            this.menu = menuId ? document.getElementById(menuId) : null;

            if (!this.menu) {
                return;
            }

            this.menuItems = this.buttons.map((button) =>
                this.menu.querySelector('[data-command-item="' + button.dataset.command + '"]'));

            this.menu.querySelectorAll('[data-command]').forEach((item) => {
                item.addEventListener('click', () => {
                    this.run(item.dataset.command);
                    this.closeMenu();
                });
            });

            this.layoutToolbar();

            if (typeof ResizeObserver !== 'undefined') {
                let frame = null;

                new ResizeObserver(() => {
                    if (frame) {
                        cancelAnimationFrame(frame);
                    }
                    frame = requestAnimationFrame(() => this.layoutToolbar());
                }).observe(this.toolbar);
            }
        }

        closeMenu() {
            if (typeof Garnish === 'undefined' || !window.$ || !this.menu) {
                return;
            }

            const disclosure = window.$(this.menu).data('disclosureMenu');

            if (disclosure) {
                disclosure.hide();
            }
        }

        /**
         * Hides buttons from the end of the toolbar, one at a time, until what's
         * left fits. The hidden ones show up in the overflow menu instead.
         */
        layoutToolbar() {
            if (!this.menu || !this.toolbar.clientWidth) {
                return;
            }

            this.buttons.forEach((button, i) => {
                button.hidden = false;
                this.setMenuItemHidden(i, true);
            });
            this.groups.forEach((group) => {
                group.hidden = false;
            });
            this.overflow.classList.add('hidden');

            if (this.fits()) {
                return;
            }

            this.overflow.classList.remove('hidden');

            for (let i = this.buttons.length - 1; i >= 0; i--) {
                this.buttons[i].hidden = true;
                this.setMenuItemHidden(i, false);

                // Don't leave an empty group behind — its divider would hang there.
                // The heading menu counts as content though it never folds
                this.groups.forEach((group) => {
                    group.hidden = !group.querySelector('[data-command]:not([hidden]), [data-headings]');
                });

                if (this.fits()) {
                    return;
                }
            }
        }

        fits() {
            return this.toolbar.scrollWidth <= this.toolbar.clientWidth;
        }

        setMenuItemHidden(index, hidden) {
            const item = this.menuItems[index];

            if (item) {
                item.classList.toggle('hidden', hidden);
            }
        }

        // -- Tabs -------------------------------------------------------------

        onTabKeydown(event) {
            if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') {
                return;
            }

            event.preventDefault();

            const current = this.tabs.findIndex((tab) => tab.getAttribute('aria-selected') === 'true');
            const next = this.tabs[(current + (event.key === 'ArrowRight' ? 1 : this.tabs.length - 1)) % this.tabs.length];

            if (next.disabled) {
                return;
            }

            this.showTab(next.dataset.tab);
            next.focus();
        }

        /**
         * There's nothing to preview until something's been written.
         */
        syncPreviewTab() {
            if (!this.previewTab) {
                return;
            }

            const empty = this.source.value.trim() === '';

            this.previewTab.disabled = empty;

            if (empty && this.previewTab.getAttribute('aria-selected') === 'true') {
                this.showTab('write');
            }
        }

        showTab(name) {
            if (!this.previewEl) {
                return;
            }

            const previewing = name === 'preview';

            if (previewing) {
                // The field's floor, not the height the editor had grown to: enough
                // that the spinner doesn't collapse the box, while letting the
                // preview take its own height. Rendered Markdown is nearly always
                // shorter than its source
                this.previewEl.style.minHeight = this.source.style.minHeight || '';
            }

            this.tabs.forEach((tab) => {
                const selected = tab.dataset.tab === name;
                tab.classList.toggle('is-selected', selected);
                tab.setAttribute('aria-selected', selected ? 'true' : 'false');
                tab.tabIndex = selected ? 0 : -1;
            });

            this.buttons.forEach((button) => {
                button.disabled = previewing;
            });

            // The toolbar's menus, whose triggers sit outside the button groups
            this.container.querySelectorAll('[data-headings] button, [data-snippets-trigger]')
                .forEach((button) => {
                    button.disabled = previewing;
                });

            if (previewing) {
                this.closeMenuIn(this.headingsMenu);
                this.closeSnippets();
            }

            // Nothing to write against while the preview is up, cheatsheet included
            if (this.guideBtn) {
                this.guideBtn.disabled = previewing;

                if (previewing) {
                    this.closeGuide();
                }
            }

            // Hide the whole stack, not just the textarea, or the highlighted
            // layer would show through the preview
            this.editorEl.hidden = previewing;
            this.previewEl.hidden = !previewing;

            if (previewing) {
                this.renderPreview();
            } else {
                this.source.focus();
                this.autoGrow();
                this.syncScroll();
            }
        }

        /**
         * The site the element being edited belongs to, so reference tags in the
         * preview resolve to the same URLs the front end will render. The element
         * editor puts it in the form; outside one, fall back to whichever site the
         * control panel is on.
         */
        siteId() {
            const input = this.container.closest('form')?.querySelector('input[name="siteId"]');

            return input?.value || window.Craft?.siteId || null;
        }

        renderPreview() {
            const markdown = this.source.value;

            if (markdown === this.previewed) {
                return;
            }

            this.previewed = markdown;

            if (markdown.trim() === '') {
                this.previewEl.innerHTML = '';
                this.previewEl.appendChild(this.note(Craft.t('wahlberg', 'Nothing to preview')));
                return;
            }

            this.previewEl.innerHTML = '<div class="spinner"></div>';

            Craft.sendActionRequest('POST', 'wahlberg/preview', {
                data: {
                    markdown: markdown,
                    fieldUid: this.config.fieldUid,
                    flavour: this.config.flavour,
                    siteId: this.siteId(),
                },
            }).then((response) => {
                this.previewEl.innerHTML = response.data.html;
            }).catch(() => {
                this.previewed = null;
                this.previewEl.innerHTML = '';
                this.previewEl.appendChild(this.note(Craft.t('wahlberg', 'The preview couldn’t be loaded.')));
            });
        }

        note(text) {
            const p = document.createElement('p');
            p.className = 'wahlberg-note';
            p.textContent = text;
            return p;
        }
    }

    window.WahlbergEditor = WahlbergEditor;
})();
