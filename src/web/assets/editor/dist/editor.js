/* global Craft, Garnish */
(function() {
    'use strict';

    const IS_MAC = /Mac|iP(hone|ad|od)/.test(navigator.platform || '');
    const MOD_KEY = IS_MAC ? 'metaKey' : 'ctrlKey';
    const MOD_LABEL = IS_MAC ? '⌘' : 'Ctrl+';
    const MOD_SHIFT_LABEL = IS_MAC ? '⌘⇧' : 'Ctrl+Shift+';

    // How much of the window a field has to fill before its header holds still and
    // lets the text scroll under it. Under this the header is barely gone before the
    // field is too, and sticking it would only mean sliding it over the last of the
    // text on the way past — motion in exchange for nothing.
    const STICKY_SHARE = 0.5;

    // A list item, split into indent / marker / spacing / content
    const LIST_ITEM = /^(\s*)([-*+]|\d+[.)])(\s+)(.*)$/;
    const BLOCKQUOTE = /^(\s*>\s?)(.*)$/;

    // Line-prefixing commands: the pattern strips an existing prefix (which is
    // also how we detect a toggle-off), the prefix function builds a new one.
    // `test` is what counts as already-applied when that's looser than the prefix
    // the command builds: a ticked task is still a task.
    const PREFIXES = {
        quote: {pattern: /^ {0,3}> ?/, prefix: () => '> '},
        // Strips a task box too, so a list pasted from elsewhere comes out clean
        ul: {pattern: /^ {0,3}[-*+] +(?:\[[ xX]\] +)?/, prefix: () => '- '},
        ol: {pattern: /^ {0,3}\d+[.)] +/, prefix: (i) => (i + 1) + '. '},
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

    /**
     * The source split into the blocks a parser turns into elements: runs of lines
     * with blank ones between them, and a fenced code block counting as one however
     * many blank lines are inside it.
     *
     * Returned as the character each block starts at.
     */
    function sourceBlocks(value) {
        const starts = [];
        let fence = null;
        let open = false;
        let at = 0;

        value.split('\n').forEach((line) => {
            const fenced = line.match(FENCE);

            if (fence) {
                // Inside a fence, where a blank line ends nothing
                if (fenced && fenced[2][0] === fence[0] && fenced[2].length >= fence.length) {
                    fence = null;
                }
            } else if (fenced) {
                if (!open) {
                    starts.push(at);
                    open = true;
                }

                fence = fenced[2];
            } else if (line.trim() === '') {
                open = false;
            } else if (HEADING.test(line)) {
                // A heading is an element of its own whether or not a blank line
                // announced it, and whatever follows starts another
                starts.push(at);
                open = false;
            } else if (!open) {
                starts.push(at);
                open = true;
            }

            at += line.length + 1;
        });

        return starts;
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

    /**
     * A throwaway copy of a textarea holding `text`, appended to `parent`.
     *
     * It wraps that text exactly as the real one does — same width, same face,
     * same padding — so anything measured inside it is in the textarea's own
     * coordinates. `parent` has to be the element the textarea sits at the top
     * left of, since the copy is positioned there.
     */
    function mirror(source, parent, text) {
        const styles = window.getComputedStyle(source);
        const el = document.createElement('div');

        CARET_STYLES.forEach((property) => {
            el.style[property] = styles[property];
        });

        el.style.position = 'absolute';
        el.style.top = '0';
        el.style.left = '0';
        el.style.height = 'auto';
        el.style.visibility = 'hidden';
        el.style.whiteSpace = 'pre-wrap';
        el.style.overflowWrap = 'break-word';
        el.style.width = source.offsetWidth + 'px';
        el.textContent = text;

        parent.appendChild(el);

        return el;
    }

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
            this.headerEl = this.container.querySelector('[data-header]');
            this.tabs = Array.from(this.container.querySelectorAll('[data-tab]'));
            this.previewTab = this.container.querySelector('[data-tab="preview"]');

            this.toolbar = this.container.querySelector('[data-toolbar]');
            this.groups = Array.from(this.container.querySelectorAll('[data-toolbar-group]'));
            this.buttons = Array.from(this.container.querySelectorAll('[data-toolbar-group] [data-command]'));
            this.overflow = this.container.querySelector('[data-overflow]');
            this.floating = this.container.querySelector('[data-floating]');
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

            // How tall the field stands writing, which is what decides whether its
            // header sticks — kept, because the preview is shorter and can't be
            // asked
            this.stickyHeight = 0;

            // The floating toolbar's state: summoned to the caret rather than
            // called up by a selection, put away with Escape, and out of the way
            // while a selection is being dragged
            this.summoned = false;
            this.dismissed = false;
            this.selecting = false;

            // The band behind the line being written. Built here rather than in the
            // template because it's decoration: nothing outside this class needs to
            // know it exists, and a field that themes it away pays for nothing.
            // First child, so it paints under both text layers
            this.activeLine = document.createElement('div');
            this.activeLine.className = 'wahlberg-active-line';
            this.activeLine.hidden = true;
            this.activeLine.setAttribute('aria-hidden', 'true');
            this.editorEl.prepend(this.activeLine);

            this.tabs.forEach((tab) => {
                // The tabs carry no title of their own, so this is the only place
                // the shortcut announces itself
                tab.title = tab.textContent.trim() + ' (' + MOD_SHIFT_LABEL + 'P)';
                tab.addEventListener('click', () => this.showTab(tab.dataset.tab));
                tab.addEventListener('keydown', (event) => this.onTabKeydown(event));
            });

            this.buttons.forEach((button) => {
                const shortcut = button.dataset.shortcut;

                if (shortcut) {
                    // `shift+E` is ⌘⇧E and `B` is ⌘B — or Ctrl, on a platform that
                    // says Ctrl
                    const shifted = shortcut.indexOf('shift+') === 0;

                    button.title += ' (' + (shifted ? MOD_SHIFT_LABEL : MOD_LABEL) +
                        (shifted ? shortcut.slice(6) : shortcut) + ')';
                }
                // Keep the textarea's selection when a button takes the click
                button.addEventListener('mousedown', (event) => event.preventDefault());
                button.addEventListener('click', () => this.run(button.dataset.command));
            });

            this.source.addEventListener('keydown', (event) => this.onKeydown(event));
            this.source.addEventListener('input', () => this.onInput());
            this.source.addEventListener('scroll', () => this.syncScroll());

            // On the field rather than the textarea, which is hidden while the
            // preview is up: the key that leaves the writing surface is the same
            // one that has to bring it back
            this.container.addEventListener('keydown', (event) => this.onFieldKeydown(event));

            // Every way the caret can move raises `selectionchange`, typing and
            // clicking included; what it doesn't cover is the caret going away,
            // hence the other two
            const moved = () => {
                this.syncActiveLine();
                this.syncFloating();
                this.syncSlashCaret();
            };

            document.addEventListener('selectionchange', moved);
            this.source.addEventListener('focus', moved);
            this.source.addEventListener('blur', moved);

            this.initOverflow();
            this.initFloating();
            this.initHeadings();
            this.initInsertMenu();
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
            this.syncActiveLine();
            this.syncSlash();
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

                // Only now does the textarea hand its text over. Setting this any
                // earlier — in the constructor, or in the stylesheet — is a field
                // that reads as empty until this paint lands
                this.container.classList.add('wahlberg--highlighted');

                this.syncScroll();
            });
        }

        syncScroll() {
            if (this.highlight) {
                this.highlight.scrollTop = this.source.scrollTop;
                this.highlight.scrollLeft = this.source.scrollLeft;
            }

            this.positionActiveLine();

            // The panel is anchored to text that's just moved under it
            this.syncFloating();
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

        // -- Active line ------------------------------------------------------

        /**
         * Repaints the band behind the line being written. Coalesced like the
         * highlighted layer is, since the caret can move on every keystroke.
         */
        syncActiveLine() {
            if (this.activeLineFrame) {
                return;
            }

            this.activeLineFrame = requestAnimationFrame(() => {
                this.activeLineFrame = null;
                this.renderActiveLine();
            });
        }

        /**
         * Up only while the field has focus and nothing is selected: a selection
         * already says where the author is, and a band on every editor on the
         * page says nothing at all.
         */
        renderActiveLine() {
            const source = this.source;

            // A frame late, by which time focus has landed — during a blur it's
            // still on its way, and the toolbar's menus take it for as long as
            // they're open
            const show = this.container.contains(document.activeElement) &&
                source.selectionStart === source.selectionEnd &&
                // Nothing to measure against while the preview is up
                !!source.offsetParent;

            const rows = show ? this.lineRows() : null;

            if (!rows) {
                this.activeLine.hidden = true;
                return;
            }

            this.activeLineTop = rows.top;
            this.activeLine.style.height = rows.height + 'px';
            this.activeLine.hidden = false;
            this.positionActiveLine();
        }

        /**
         * Where the caret's line sits, and how tall it is, in the textarea's own
         * coordinates.
         *
         * A long line wraps over several rows and the band covers all of them, so
         * what's wanted is the block the line occupies rather than the one row the
         * caret is on. Measured on a copy of the textarea with the line in a span
         * of its own: an inline element reports one client rect per row it takes,
         * which is the question being asked, and asking beats working out where
         * the text would break from the width and the face.
         */
        lineRows() {
            const source = this.source;
            const value = source.value;

            const from = value.lastIndexOf('\n', source.selectionStart - 1) + 1;
            let to = value.indexOf('\n', source.selectionStart);

            if (to === -1) {
                to = value.length;
            }

            // Only the text above the line affects where it lands, so the rest of
            // the document is left out of the copy
            const copy = mirror(source, this.editorEl, value.slice(0, from));
            const line = document.createElement('span');

            // Zero-width filler, so an empty line still has a row to report
            line.textContent = value.slice(from, to) || '​';
            copy.appendChild(line);

            const rects = line.getClientRects();
            const first = rects[0];
            const top = copy.getBoundingClientRect().top;

            copy.remove();

            if (!first) {
                return null;
            }

            // A client rect covers the text's own height, ascent to descent, and
            // not the row it sits in. The leading is the difference, half of it
            // above and half below, so it goes back on here: without it the band
            // comes out shorter than the caret, which is drawn to the row.
            const lineHeight = parseFloat(window.getComputedStyle(source).lineHeight) || first.height;
            const leading = (lineHeight - first.height) / 2;

            return {
                top: first.top - top - leading,
                // One rect per row, each of them a whole row tall
                height: rects.length * lineHeight,
            };
        }

        /**
         * The band is positioned in the editor, not in the text, so it has to be
         * moved by however far the text has scrolled under it.
         */
        positionActiveLine() {
            if (!this.activeLine.hidden) {
                this.activeLine.style.top = (this.activeLineTop - this.source.scrollTop) + 'px';
            }
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
        driveMenu(menu, close, focus = true) {
            // Read fresh each time rather than captured: `/` narrows the list as the
            // author types, and arrowing onto something that's been filtered out
            // would be arrowing onto nothing
            const visible = () => Array.from(menu.querySelectorAll('.menu-item:not(.disabled)'))
                .filter((item) => !item.closest('.filtered'));

            if (!visible().length) {
                return;
            }

            let current = null;

            const highlight = (to) => {
                const items = visible();

                if (!items.length) {
                    return;
                }

                const at = items.indexOf(current);
                const index = ((to === null ? at : to) + items.length) % items.length;

                current = items[index];

                menu.querySelectorAll('.menu-item').forEach((item) =>
                    item.classList.toggle('is-highlighted', item === current));

                // Best effort: it's what gives Craft's focus ring, but the class
                // above is what guarantees the highlight is visible either way.
                // Not when `/` opened it — focus has to stay in the textarea, or
                // the next keystroke would go to the menu instead of the query
                if (focus) {
                    current.focus();
                }
            };

            const step = (by) => {
                const items = visible();
                const at = items.indexOf(current);

                highlight(at === -1 ? 0 : at + by);
            };

            const stop = () => {
                document.removeEventListener('keydown', onKeydown, true);
                document.removeEventListener('input', onInput, true);
                menu.querySelectorAll('.menu-item').forEach((item) =>
                    item.classList.remove('is-highlighted'));
                this.drivenMenu = null;
            };

            // Filtering can take the highlighted item away, so it moves back to the
            // top of whatever's left
            const onInput = () => {
                if (current && current.closest('.filtered')) {
                    current = null;
                    highlight(0);
                }
            };

            const onKeydown = (event) => {
                // Closed by a click elsewhere, or typed out of
                if (!menu.classList.contains('visible')) {
                    stop();
                    return;
                }

                const keys = {
                    ArrowDown: () => step(1),
                    ArrowUp: () => step(-1),
                    Home: () => highlight(0),
                    End: () => highlight(visible().length - 1),
                    Tab: () => step(event.shiftKey ? -1 : 1),
                };

                if (keys[event.key]) {
                    event.preventDefault();
                    event.stopPropagation();
                    keys[event.key]();
                    return;
                }

                if (event.key === 'Enter' && current) {
                    event.preventDefault();
                    event.stopPropagation();
                    stop();
                    current.click();
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
            document.addEventListener('input', onInput, true);
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

        // -- The insert menu --------------------------------------------------
        //
        // One menu, three ways in. `/` opens the lot; the Snippets button and ⌘⇧K
        // open it with the commands left out, since both of those have meant
        // snippets since before there was anything else in it.

        initInsertMenu() {
            this.insertMenu = this.container.querySelector('[data-insert-menu]');

            if (!this.insertMenu) {
                return;
            }

            this.commandGroup = this.insertMenu.querySelector('[data-command-group]');
            this.divider = this.insertMenu.querySelector('hr');

            // Where the `/` that opened the menu sits, so what's typed after it can
            // be read back as a query and taken out again on the way in
            this.slashAt = null;

            const trigger = this.container.querySelector('[data-snippets-trigger]');

            if (trigger) {
                trigger.title += ' (' + MOD_SHIFT_LABEL + 'K)';
                trigger.addEventListener('mousedown', (event) => event.preventDefault());
                // Anchored to itself when clicked, at the caret from the shortcut:
                // a click comes from the toolbar, so that's where the eye is
                trigger.addEventListener('click', () => {
                    if (this.insertOpen()) {
                        this.closeInsert();
                    } else {
                        this.openInsert(trigger, false);
                    }
                });
            }

            this.insertMenu.querySelectorAll('[data-snippet], [data-command]').forEach((item) => {
                item.addEventListener('mousedown', (event) => event.preventDefault());
                item.addEventListener('click', () => this.runInsert(item));
            });

            document.addEventListener('mousedown', (event) => {
                if (this.insertOpen() && !this.insertMenu.contains(event.target) &&
                    event.target !== trigger && !trigger?.contains(event.target)
                ) {
                    this.closeInsert();
                }
            });
        }

        insertOpen() {
            return !!this.insertMenu && this.insertMenu.classList.contains('visible');
        }

        /**
         * Opens the insert menu, at the caret or under whatever opened it.
         *
         * The caret is the default because that's where whatever's picked is going,
         * and because `/` and the shortcut have to work when the button isn't on the
         * toolbar at all. `visible` rather than an attribute of our own: Craft hides
         * `.menu:not(.visible)` outright, and that outranks anything the plugin's
         * own stylesheet says about display.
         *
         * `commands` is what tells the two snippet triggers from `/`: the button and
         * ⌘⇧K have meant snippets since before there was anything else in here.
         */
        openInsert(anchor, commands) {
            if (!this.insertMenu || this.insertOpen()) {
                return;
            }

            if (this.commandGroup) {
                this.commandGroup.classList.toggle('filtered', !commands);
                this.divider?.classList.toggle('filtered', !commands);
            }

            this.insertMenu.classList.add('visible');

            const at = anchor ? this.below(anchor) : this.atCaret();
            const width = this.insertMenu.offsetWidth;

            // Right-aligned under a toolbar button, which sits at the end of the
            // header and would otherwise push the menu off the edge
            const left = anchor ? at.right - width : at.left;
            const room = this.container.clientWidth - width;

            this.insertMenu.style.top = at.top + 'px';
            this.insertMenu.style.left = Math.max(0, Math.min(left, room)) + 'px';

            this.container.querySelectorAll('[data-snippets-trigger]')
                .forEach((button) => button.setAttribute('aria-expanded', 'true'));

            // Opened by typing, focus stays in the textarea so the author can carry
            // on typing to narrow the list. Opened any other way it moves into the
            // menu, which is what gives Craft's focus ring
            this.driveMenu(this.insertMenu, () => this.closeInsert(), this.slashAt === null);
        }

        closeInsert() {
            this.slashAt = null;

            if (!this.insertOpen()) {
                return;
            }

            this.insertMenu.classList.remove('visible');
            this.filterInsert('');

            this.container.querySelectorAll('[data-snippets-trigger]')
                .forEach((button) => button.setAttribute('aria-expanded', 'false'));
        }

        // -- Typing `/` -------------------------------------------------------
        //
        // A menu at the caret, listing the things that go in at one. It's the only
        // way to reach the element pickers without the toolbar — which a field with
        // a floating toolbar hasn't got until something is selected, and the whole
        // point of an entry link is that there isn't.

        /**
         * Called on every keystroke: opens the menu on a `/` that starts something,
         * and once it's open keeps it in step with what's been typed since.
         */
        syncSlash() {
            if (!this.insertMenu) {
                return;
            }

            if (this.slashAt === null) {
                this.openSlash();
                return;
            }

            const query = this.slashQuery();

            if (query === null) {
                this.closeInsert();
                return;
            }

            this.filterInsert(query);
        }

        /**
         * The caret has moved without anything being typed. Only ever closes: an
         * opening is something the author did, not somewhere they clicked.
         */
        syncSlashCaret() {
            if (this.slashAt !== null && this.slashQuery() === null) {
                this.closeInsert();
            }
        }

        openSlash() {
            const source = this.source;
            const at = source.selectionStart - 1;

            // The slash just typed, and only where one starts something: at the
            // beginning of a line or after a space. Markdown source is full of the
            // other kind — URLs, paths, closing tags, dates
            if (source.selectionStart !== source.selectionEnd ||
                at < 0 || source.value[at] !== '/' ||
                (at > 0 && !/\s/.test(source.value[at - 1]))
            ) {
                return;
            }

            // Inside a fenced block a slash is a path far more often than it's a
            // command, and an author writing code shouldn't be interrupted
            if (this.inFence(at)) {
                return;
            }

            this.slashAt = at;
            this.filterInsert('');
            this.openInsert(null, true);
        }

        /**
         * What's been typed since the slash, or null if the menu has been typed or
         * clicked out of.
         */
        slashQuery() {
            const source = this.source;

            // The caret went back past it, or the slash itself has been deleted
            if (source.selectionStart <= this.slashAt || source.value[this.slashAt] !== '/') {
                return null;
            }

            const query = source.value.slice(this.slashAt + 1, source.selectionStart);

            // A space ends it. Someone who types `/ ` was writing, not choosing
            return /\s/.test(query) ? null : query;
        }

        /**
         * Narrows the menu to what matches, and closes it when nothing does — which
         * is what makes a false opening cost a flicker rather than a dismissal.
         */
        filterInsert(query) {
            if (!this.insertMenu) {
                return;
            }

            const needle = query.toLowerCase();
            let matches = 0;

            this.insertMenu.querySelectorAll('li').forEach((li) => {
                const hit = li.textContent.toLowerCase().indexOf(needle) !== -1;
                li.classList.toggle('filtered', !hit);

                if (hit) {
                    matches++;
                }
            });

            // A group with nothing left in it, and the divider that was separating
            // it from something
            const groups = Array.from(this.insertMenu.querySelectorAll('ul'));

            groups.forEach((ul) => {
                ul.classList.toggle('filtered', !ul.querySelector('li:not(.filtered)'));
            });

            this.divider?.classList.toggle(
                'filtered',
                groups.some((ul) => ul.classList.contains('filtered')),
            );

            if (query !== '' && !matches) {
                this.closeInsert();
            }
        }

        /**
         * Whether a position sits inside a fenced code block.
         */
        inFence(index) {
            let fence = null;

            for (const line of this.source.value.slice(0, index).split('\n')) {
                const fenced = line.match(FENCE);

                if (!fenced) {
                    continue;
                }

                if (!fence) {
                    fence = fenced[2];
                } else if (fenced[2][0] === fence[0] && fenced[2].length >= fence.length) {
                    fence = null;
                }
            }

            return fence !== null;
        }

        /**
         * Takes the `/query` back out and does whatever was picked.
         *
         * Out first, and as an edit of its own: it was the way into the menu rather
         * than anything to keep, and leaving it in place would have a snippet take
         * it for the selection it's meant to wrap.
         */
        runInsert(item) {
            const command = item.dataset.command;
            const handle = item.dataset.snippet;

            this.clearSlash();
            this.closeInsert();

            if (command) {
                this.run(command);
            } else {
                this.insertSnippet(handle);
            }
        }

        clearSlash() {
            const source = this.source;

            if (this.slashAt === null || source.value[this.slashAt] !== '/' ||
                source.selectionStart <= this.slashAt
            ) {
                return;
            }

            source.setSelectionRange(this.slashAt, source.selectionStart);
            this.insert('', 0, 0);
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
            const copy = mirror(source, this.editorEl, source.value.slice(0, source.selectionStart));

            // Zero-width, so it can't wrap onto a line of its own
            const marker = document.createElement('span');
            marker.textContent = '\u200b';
            copy.appendChild(marker);

            const top = marker.offsetTop - source.scrollTop + parseFloat(styles.lineHeight || 20);
            const left = marker.offsetLeft - source.scrollLeft;

            copy.remove();

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

            // As every other control on the toolbar does. It matters more here than
            // it looks: the cheatsheet is read against something half-written, so
            // the selection has to survive opening it — and a floating toolbar is
            // up for exactly as long as the field has the focus this would take
            this.guideBtn.addEventListener('mousedown', (event) => event.preventDefault());
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

            // The count twice: once formatted for reading, once raw for the plural
            // rule to pick a branch with. A `#` inside a plural comes out as the
            // bare number, which is `1234 words` where the limit beside it already
            // says `1,234`
            const count = (message, n) => Craft.t('wahlberg', message, {
                n: n,
                count: Craft.formatNumber(n),
            });

            const parts = [];

            if (limit) {
                parts.push(Craft.t('wahlberg', '{n} of {limit}', {
                    n: Craft.formatNumber(counted),
                    limit: Craft.formatNumber(limit),
                }));
            } else {
                parts.push(count(
                    '{n, plural, =1{1 character} other{{count} characters}}',
                    Array.from(value).length,
                ));
            }

            parts.push(count('{n, plural, =1{1 word} other{{count} words}}', words));
            parts.push(count(
                '{n, plural, =1{1 line} other{{count} lines}}',
                value === '' ? 0 : value.split('\n').length,
            ));

            this.stats.textContent = parts.join(' · ');
            this.stats.classList.toggle('is-over', !!limit && counted > limit);
        }

        // -- Typing -----------------------------------------------------------

        onKeydown(event) {
            if (event[MOD_KEY] && event.shiftKey && !event.altKey) {
                const key = event.key.toLowerCase();

                // Snippets only, as it always has been — and only where the field
                // has any, since the menu now exists for the commands alone too
                if (key === 'k' && this.insertMenu?.querySelector('[data-snippet]')) {
                    event.preventDefault();
                    this.openInsert(null, false);
                    return;
                }

                if (key === 'f' && this.floating) {
                    event.preventDefault();
                    this.toggleFloating();
                    return;
                }

                // The two element pickers, bound whether or not their buttons are
                // on the toolbar, as the unshifted three are. They matter most to a
                // field with a floating toolbar, where reaching a button means
                // picking out text first — and inserting an entry is the one thing
                // an author does with nothing selected
                // `U` rather than the `A` that would have matched Asset: macOS
                // browsers take ⌘⇧A for themselves, and it never reaches the page
                const command = {e: 'entry', u: 'asset'}[key];

                if (command) {
                    event.preventDefault();
                    this.run(command);
                    return;
                }
            }

            // Shift excluded, or the shifted shortcuts above would lowercase into
            // these and insert a link instead
            if (event[MOD_KEY] && !event.altKey && !event.shiftKey) {
                const command = {b: 'bold', i: 'italic', k: 'link'}[event.key.toLowerCase()];

                if (command) {
                    event.preventDefault();
                    this.run(command);
                    return;
                }
            }

            // Whatever the panel was up for is over — unless something it opened is
            // still up, in which case Escape belongs to that. Stopped here rather
            // than left to bubble, since a visible thing closing is what Escape did
            if (event.key === 'Escape' && this.floating && !this.floating.hidden &&
                !this.floatingBusy()
            ) {
                event.preventDefault();
                event.stopPropagation();
                this.dismissed = true;
                this.hideFloating();
                return;
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
            this.measureStickyTop();
            this.syncSticky();

            // A field that stayed the same size can still cross the threshold, by
            // the window changing height under it — and the page header it's
            // clearing is a different height at a different width
            window.addEventListener('resize', () => {
                this.measureStickyTop();
                this.syncSticky();
            });

            if (typeof ResizeObserver === 'undefined') {
                return;
            }

            // Two jobs: re-fit when the editor's width changes (rewrapping
            // changes the height it needs), and notice when the author has
            // dragged the resize handle so we can leave the height alone
            let lastWidth = this.source.offsetWidth;

            new ResizeObserver(() => {
                // Ahead of everything below, since this is the one thing that has
                // to keep up with a height the author set by dragging — which is
                // exactly the case `autoGrow()` bows out of
                this.syncSticky();

                const width = this.source.offsetWidth;

                if (width !== lastWidth) {
                    lastWidth = width;
                    this.autoGrow();
                    // Narrower or wider wraps the lines differently, and the band
                    // is the height of however many rows its line now takes. The
                    // panel is anchored to a row that's moved for the same reason
                    this.syncActiveLine();
                    this.syncFloating();
                    return;
                }

                if (this.autoHeight !== undefined && Math.abs(this.source.offsetHeight - this.autoHeight) > 1) {
                    this.manuallySized = true;
                }
            }).observe(this.source);
        }

        /**
         * How far down the sticky header has to start.
         *
         * Craft pins the page header to the top of the window once the page scrolls,
         * and a field header stuck at the top of the window would hold still
         * underneath it. So the offset is that header's height — but only where the
         * page is what's scrolling. In a slideout, or anywhere else with a scrolling
         * box of its own, our header sticks to the top of that box, which is already
         * below whatever chrome the thing has.
         *
         * Kept separate from `--wahlberg-sticky-top`, which is what a control panel
         * with something else along the top sets to overrule all of this.
         */
        measureStickyTop() {
            const header = this.pageScrolls() ? document.querySelector('#header') : null;

            this.container.style.setProperty(
                '--wahlberg-header-offset',
                (header ? header.offsetHeight : 0) + 'px',
            );
        }

        /**
         * Whether the page is what scrolls this field, rather than a box it's inside.
         *
         * Anything that scrolls or clips counts, since either way it's a scrollport,
         * and a sticky element holds against the nearest one of those rather than
         * against the window.
         */
        pageScrolls() {
            for (let node = this.container.parentElement; node && node !== document.body; node = node.parentElement) {
                const overflow = window.getComputedStyle(node).overflowY;

                if (overflow === 'auto' || overflow === 'scroll' || overflow === 'hidden') {
                    return false;
                }
            }

            return true;
        }

        /**
         * Decides whether the header holds still while the text scrolls under it.
         *
         * Worth it on a field big enough to be read and written with its own header
         * off the top of the screen, and not on one that's gone almost as soon as
         * its header is. Measured against the window rather than counted in rows,
         * since what matters is how much of the screen the field takes up, and a row
         * is a different fraction of that on every display.
         */
        syncSticky() {
            // The height the field has writing, whichever tab is up. Rendered
            // Markdown is shorter than the source it came from, so measuring the
            // preview would unstick the header of a field that's going to stick
            // again the moment the author switches back — and a header that comes
            // and goes with the tabs is worse than one that never held still.
            // Remembered rather than measured while previewing, since a hidden box
            // has no height to read
            if (!this.previewing()) {
                this.stickyHeight = this.container.offsetHeight;
            }

            this.container.classList.toggle(
                'wahlberg--sticky',
                this.stickyHeight > window.innerHeight * STICKY_SHARE,
            );
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

        // -- Floating toolbar -------------------------------------------------
        //
        // The same toolbar, out of the header and over the text: up when there's a
        // selection to act on, and on ⌘⇧F at the caret for the commands that don't
        // need one.

        initFloating() {
            if (!this.floating) {
                return;
            }

            // A drag is a selection being made rather than one to act on, and a
            // panel chasing the cursor is a panel in the way. It comes back when
            // the mouse comes up
            this.source.addEventListener('mousedown', () => {
                this.selecting = true;
                this.hideFloating();
            });

            document.addEventListener('mouseup', () => {
                if (this.selecting) {
                    this.selecting = false;
                    this.syncFloating();
                }
            });
        }

        /**
         * Puts the panel where the selection is, or takes it away. Coalesced like
         * the band behind the line, since the caret can move on every keystroke.
         */
        syncFloating() {
            if (!this.floating || this.floatingFrame) {
                return;
            }

            this.floatingFrame = requestAnimationFrame(() => {
                this.floatingFrame = null;
                this.renderFloating();
            });
        }

        renderFloating() {
            const source = this.source;
            const range = source.selectionStart + ':' + source.selectionEnd;

            // Escape puts the panel away for this selection only. Anything else
            // and there'd be no way back to it without leaving the field
            if (range !== this.floatingRange) {
                this.floatingRange = range;
                this.dismissed = false;
            }

            if (!this.wantsFloating()) {
                this.hideFloating();
                return;
            }

            // Shown before it's measured: hidden it has no width to fold the
            // buttons against, and nothing to position. Only on the way up —
            // while it's up, the toolbar's own observer catches a field that's
            // been resized under it
            if (this.floating.hidden) {
                this.floating.hidden = false;
                this.layoutToolbar();
            }

            if (!this.positionFloating()) {
                this.hideFloating();
            }
        }

        /**
         * Whether the panel should be up: something picked out to act on, or ⌘⇧F at
         * the caret, with the field's own focus and nothing in the way.
         */
        wantsFloating() {
            const source = this.source;

            // Nothing to format, and nothing to measure against
            if (this.previewing() || !source.offsetParent) {
                return false;
            }

            if (this.floatingBusy()) {
                return true;
            }

            if (this.dismissed || this.selecting ||
                !this.container.contains(document.activeElement)
            ) {
                return false;
            }

            return !!this.summoned || source.selectionStart !== source.selectionEnd;
        }

        /**
         * Whether something the panel opened is still up: one of the menus, the
         * snippet list, the cheatsheet.
         *
         * Each of them is anchored to a button on the panel, so taking the panel
         * away would take the thing the author is reading with it — and Escape
         * belongs to them before it belongs to the panel, for the same reason.
         *
         * Keyed off the ARIA state each of them keeps in step rather than off
         * anything of ours, which is how the button styling reads it too.
         */
        floatingBusy() {
            return !!this.floating && !this.floating.hidden &&
                !!this.floating.querySelector('[aria-expanded="true"]');
        }

        /**
         * ⌘⇧F: the panel at the caret, with nothing selected.
         *
         * Half of what the toolbar offers goes in where the author is typing — a
         * heading, a list, a quote, an entry, a snippet — and a panel that only
         * ever appears over a selection would put all of that behind selecting
         * something first. Stays up until Escape, or until focus leaves the field.
         */
        toggleFloating() {
            if (this.summoned) {
                this.hideFloating();
                return;
            }

            this.summoned = true;
            this.dismissed = false;
            this.syncFloating();
        }

        hideFloating() {
            if (!this.floating) {
                return;
            }

            this.floating.hidden = true;
            this.summoned = false;
        }

        /**
         * Where the selection sits, in the field's own coordinates: the rows the
         * panel has to clear, and the point it should aim at.
         *
         * Measured on a throwaway copy of the textarea, the same trick the caret and
         * the active line are found with. An inline element reports one client rect
         * per row it takes, so a selection running over several rows gives up its
         * first row and its last, which are the two the panel can sit against.
         */
        selectionBox() {
            const source = this.source;
            const value = source.value;

            const copy = mirror(source, this.editorEl, value.slice(0, source.selectionStart));
            const span = document.createElement('span');

            // Zero-width filler, so a caret with nothing selected still has a rect
            span.textContent = value.slice(source.selectionStart, source.selectionEnd) || '​';
            copy.appendChild(span);

            const rects = Array.from(span.getClientRects());
            const base = copy.getBoundingClientRect();
            copy.remove();

            if (!rects.length) {
                return null;
            }

            const first = rects[0];
            const last = rects[rects.length - 1];

            // In the writing surface, with the text scrolled under it
            const top = first.top - base.top - source.scrollTop;
            const bottom = last.bottom - base.top - source.scrollTop;

            // Scrolled out of sight in a field that's hit its maximum height, so
            // there's nothing on screen left to point at
            if (bottom < 0 || top > this.editorEl.clientHeight) {
                return null;
            }

            const container = this.container.getBoundingClientRect();
            const editor = this.editorEl.getBoundingClientRect();
            const offset = editor.top - container.top;
            const inset = editor.left - container.left - source.scrollLeft;

            return {
                top: top + offset,
                bottom: bottom + offset,
                // The middle of whichever row the panel ends up beside
                above: first.left - base.left + first.width / 2 + inset,
                below: last.left - base.left + last.width / 2 + inset,
                // The same rows on the page, for working out which side has room
                pageTop: editor.top + top,
                pageBottom: editor.top + bottom,
            };
        }

        /**
         * Puts the panel under the selection, or over it where there's no room
         * below, and points the arrow back at the text.
         */
        positionFloating() {
            const box = this.selectionBox();

            if (!box) {
                return false;
            }

            const panel = this.floating;
            const width = panel.offsetWidth;
            const height = panel.offsetHeight;

            // The arrow, and a little daylight over the text
            const gap = 10;

            // Below, where the eye already is once something's been picked out,
            // unless that would land the panel off the bottom of the window and
            // there's room the other way
            const below = box.pageBottom + gap + height <= window.innerHeight ||
                box.pageTop - gap - height < 0;

            const at = below ? box.below : box.above;
            const top = below ? box.bottom + gap : box.top - gap - height;

            // Held inside the field, which for a panel as wide as one means pinned
            // to its start edge
            const left = Math.max(0, Math.min(at - width / 2, this.container.clientWidth - width));

            panel.classList.toggle('wahlberg-floating--above', !below);
            panel.style.top = Math.round(top) + 'px';
            panel.style.left = Math.round(left) + 'px';

            // The arrow stays on the text however far the panel had to move to fit,
            // stopping short of the corners, where it would have no edge to sit on
            panel.style.setProperty('--wahlberg-arrow', Math.round(
                Math.min(Math.max(at - left, 12), Math.max(width - 12, 12)),
            ) + 'px');

            return true;
        }

        // -- Keeping your place -----------------------------------------------
        //
        // Switching tabs swaps one document for another: the same content, at a
        // different length, in a different shape. Matching by pixel or by percentage
        // doesn't survive that — a link is a URL's worth of source and a word of
        // rendered text, and a reference tag is worse — so what's matched is
        // structure. Each block of source comes out of the parser as one top-level
        // element, so the block at the top of one pane is the element to put at the
        // top of the other.

        /**
         * The band across the top of the window the sticky header can occupy:
         * whatever it's clearing, plus its own height.
         *
         * What it can occupy rather than what it currently does, and its height
         * rather than where it is. The header only sticks on a field over the
         * threshold, and the same field is over it writing and under it previewing,
         * since rendered Markdown is shorter than its source. Reading the header's
         * live position would therefore give one answer on the way out and another
         * on the way back, and the difference between them is a page that creeps a
         * little further every time the author switches tabs.
         */
        stickyAllowance() {
            const styles = window.getComputedStyle(this.container);

            // As the stylesheet resolves it: the override first, then the measured
            // page header
            const top = styles.getPropertyValue('--wahlberg-sticky-top').trim() ||
                styles.getPropertyValue('--wahlberg-header-offset').trim();

            return (parseFloat(top) || 0) + (this.headerEl ? this.headerEl.offsetHeight : 0);
        }

        /**
         * The top of what's actually on screen, in viewport coordinates: the top of
         * the pane, or the underside of the header's band where that's over it.
         */
        visibleTop(el) {
            return Math.max(el.getBoundingClientRect().top, this.stickyAllowance());
        }

        /**
         * Where each block of source starts, in the textarea's own coordinates.
         *
         * Measured on one throwaway copy of the textarea with a zero-width marker at
         * every block boundary — the same trick the caret is found with, for a
         * handful of points rather than one. Zero-width because a marker that took
         * up room would wrap the text differently from the textarea it's standing in
         * for.
         */
        sourceOffsets() {
            const value = this.source.value;
            const starts = sourceBlocks(value);

            if (!starts.length) {
                return [];
            }

            const copy = mirror(this.source, this.editorEl, '');
            const markers = [];
            let at = 0;

            starts.forEach((start) => {
                copy.appendChild(document.createTextNode(value.slice(at, start)));
                at = start;

                const marker = document.createElement('span');
                marker.textContent = '​';
                copy.appendChild(marker);
                markers.push(marker);
            });

            copy.appendChild(document.createTextNode(value.slice(at)));

            const base = copy.getBoundingClientRect().top;
            const offsets = markers.map((marker) => marker.getBoundingClientRect().top - base);

            copy.remove();

            return offsets;
        }

        /**
         * The same for the preview, where the blocks are elements and there's nothing
         * to measure on a copy: they're already laid out.
         */
        previewOffsets() {
            const base = this.previewEl.getBoundingClientRect().top - this.previewEl.scrollTop;

            return Array.from(this.previewEl.children)
                .map((el) => el.getBoundingClientRect().top - base);
        }

        /**
         * Which block the visible top of a pane has reached, and how far into it.
         */
        anchorIn(el, offsets) {
            if (!offsets.length) {
                return null;
            }

            const at = this.visibleTop(el) - el.getBoundingClientRect().top + el.scrollTop;

            // Above the first block, so the author is at the top of the field and
            // there's nothing to keep: the other pane starts at the top as well, and
            // moving the page to say so would only be a jolt
            if (at <= offsets[0]) {
                return null;
            }

            let index = 0;

            while (index + 1 < offsets.length && offsets[index + 1] <= at) {
                index++;
            }

            const span = (offsets[index + 1] ?? offsets[index] + 1) - offsets[index];

            return {
                index: index,
                // What the index is out of, so the other pane can tell whether the
                // two documents came out the same shape
                of: offsets.length,
                fraction: span > 0 ? Math.min(1, Math.max(0, (at - offsets[index]) / span)) : 0,
            };
        }

        /**
         * Scrolls a pane to the block the other one was showing.
         */
        applyAnchor(el, offsets, anchor) {
            if (!anchor || !offsets.length) {
                return;
            }

            // One block, one element — nearly always. Where the two disagree, the
            // index is scaled rather than trusted: a list with blank lines between
            // its items is several blocks of source and a single `<ul>`, and landing
            // in the right region beats landing on the wrong paragraph
            const scaled = anchor.of === offsets.length
                ? anchor.index
                : Math.round(anchor.index * (offsets.length - 1) / Math.max(1, anchor.of - 1));

            const index = Math.min(offsets.length - 1, Math.max(0, scaled));
            const span = (offsets[index + 1] ?? offsets[index] + 1) - offsets[index];
            const target = offsets[index] + anchor.fraction * span;

            const rect = el.getBoundingClientRect();
            const top = this.visibleTop(el);

            // A field at its maximum height scrolls its own text, and the page makes
            // up whatever's left over. Not one or the other: a textarea reports the
            // height of everything in it whether or not it's scrolling any of it, so
            // asking `scrollHeight` alone would have the page stand still while a
            // `overflow: hidden` textarea was told to scroll and quietly didn't
            const overflow = window.getComputedStyle(el).overflowY;
            const scrolls = overflow === 'auto' || overflow === 'scroll';
            const room = scrolls ? Math.max(0, el.scrollHeight - el.clientHeight) : 0;

            // Where the pane would have to be scrolled to for the block to land on
            // its own, and how far it can actually go
            const scrolled = Math.max(0, Math.min(target - (top - rect.top), room));

            if (scrolls) {
                el.scrollTop = scrolled;
            }

            // The pane's own top doesn't move when its text does, so what's left is
            // the page's to cover
            const delta = rect.top + target - scrolled - top;

            // A field that fits on screen has nowhere to go, and would only jump
            if (Math.abs(delta) > 1) {
                window.scrollBy(0, delta);
            }
        }

        /**
         * Puts the preview where the source was, once there's something to put it
         * against.
         *
         * Twice, where there are images: one that hasn't loaded has no height yet,
         * so anything under it slides down the moment it arrives.
         */
        restorePreview() {
            this.applyAnchor(this.previewEl, this.previewOffsets(), this.anchor);

            this.previewEl.querySelectorAll('img').forEach((img) => {
                if (img.complete) {
                    return;
                }

                img.addEventListener('load', () => {
                    // Unless the author has moved on, in which case it isn't ours
                    // to correct any more
                    if (this.previewing()) {
                        this.applyAnchor(this.previewEl, this.previewOffsets(), this.anchor);
                    }
                }, {once: true});
            });
        }

        // -- Tabs -------------------------------------------------------------

        /**
         * Swaps between writing and previewing, from anywhere in the field.
         */
        onFieldKeydown(event) {
            if (!event[MOD_KEY] || !event.shiftKey || event.altKey ||
                event.key.toLowerCase() !== 'p'
            ) {
                return;
            }

            // Source-only field, or nothing written to preview yet
            if (!this.previewTab || this.previewTab.disabled) {
                return;
            }

            event.preventDefault();
            this.showTab(this.previewing() ? 'write' : 'preview');
        }

        previewing() {
            return !!this.previewTab && this.previewTab.getAttribute('aria-selected') === 'true';
        }

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

            if (empty && this.previewing()) {
                this.showTab('write');
            }
        }

        showTab(name) {
            if (!this.previewEl) {
                return;
            }

            const previewing = name === 'preview';

            // Already here. Clicking the tab you're on shouldn't do anything, and
            // measuring the pane that's hidden would read every rect as zero — which
            // resolves to the last block in the document, and jumps there
            if (previewing === this.previewing()) {
                return;
            }

            // Where the pane being left had got to, taken while it's still laid out
            this.anchor = previewing
                ? this.anchorIn(this.source, this.sourceOffsets())
                : this.anchorIn(this.previewEl, this.previewOffsets());

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
                this.closeInsert();
                this.hideFloating();
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

            // `preventScroll` on both: focus drags whatever it lands on into view,
            // which for a textarea means the caret and for a tab means the top of
            // the field — neither of which is where the author was reading. The
            // anchor below is what decides that
            if (previewing) {
                // Focus follows the author out of the textarea, so the shortcut
                // that got here is still inside the field on the way back
                this.previewTab.focus({preventScroll: true});
                this.renderPreview();
            } else {
                this.source.focus({preventScroll: true});
                this.autoGrow();
                this.syncScroll();

                // After `autoGrow()`, which is what settles the height everything
                // here is measured against
                this.applyAnchor(this.source, this.sourceOffsets(), this.anchor);
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

            // Already showing this, so there's nothing to fetch — but the author
            // has come back to it from somewhere else in the document
            if (markdown === this.previewed) {
                this.restorePreview();
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
                this.restorePreview();
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
