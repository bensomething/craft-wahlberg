/* global Craft, Garnish */
(function() {
    'use strict';

    const IS_MAC = /Mac|iP(hone|ad|od)/.test(navigator.platform || '');
    const MOD_KEY = IS_MAC ? 'metaKey' : 'ctrlKey';
    const MOD_LABEL = IS_MAC ? '⌘' : 'Ctrl+';

    // A list item, split into indent / marker / spacing / content
    const LIST_ITEM = /^(\s*)([-*+]|\d+[.)])(\s+)(.*)$/;
    const BLOCKQUOTE = /^(\s*>\s?)(.*)$/;

    // Line-prefixing commands: the pattern strips an existing prefix (which is
    // also how we detect a toggle-off), the prefix function builds a new one.
    const PREFIXES = {
        heading: {pattern: /^#{1,6} +/, prefix: () => '### '},
        quote: {pattern: /^ {0,3}> ?/, prefix: () => '> '},
        ul: {pattern: /^ {0,3}[-*+] +/, prefix: () => '- '},
        ol: {pattern: /^ {0,3}\d+[.)] +/, prefix: (i) => (i + 1) + '. '},
    };

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
     * up no width — but the textarea's caret still advances past them, and the
     * two layers part company by exactly that many characters. A non-breaking
     * space can't hang, and in a monospace font it's the same width as a space.
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

    // Copied onto the probe so it renders the sample exactly as the real element would
    const METRIC_STYLES = [
        'fontFamily', 'fontSize', 'fontStyle', 'fontWeight', 'fontStretch', 'fontVariant',
        'fontFeatureSettings', 'fontVariantLigatures', 'fontKerning', 'fontOpticalSizing',
        'letterSpacing', 'wordSpacing', 'textRendering', 'textTransform', 'webkitFontSmoothing',
    ];

    /**
     * The width of one character as the given element would render it, measured
     * on an off-screen copy so nothing on screen has to be disturbed.
     */
    function measureAdvance(element, isTextarea, sample) {
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
            this.initSizing();
            this.alignMetrics();
            this.renderHighlight();
            this.syncPreviewTab();

            // Webfonts can land after this runs and change the metrics under us
            if (document.fonts && document.fonts.ready) {
                document.fonts.ready.then(() => this.alignMetrics());
            }
        }

        onInput() {
            this.autoGrow();
            this.renderHighlight();
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

            const drift = source - layer;

            // Leave it alone unless the drift would show up within a line or two
            this.highlight.style.letterSpacing = Math.abs(drift) > 0.005 ? drift.toFixed(4) + 'px' : '';

            // Handy when something still looks off: compare these two in devtools
            this.highlight.dataset.advance = source.toFixed(4) + '/' + layer.toFixed(4);
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
                this.prefixLines(PREFIXES[command].pattern, PREFIXES[command].prefix);
            } else {
                switch (command) {
                    case 'bold':
                        this.wrap('**', '**');
                        break;
                    case 'italic':
                        this.wrap('_', '_');
                        break;
                    case 'code':
                        this.code();
                        break;
                    case 'link':
                        this.link();
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
        prefixLines(pattern, prefix) {
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
            const filled = lines.filter((line) => line.trim() !== '');
            const prefixed = filled.length > 0 && filled.every((line) => pattern.test(line));

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

            // A URL was selected — put the caret where the link text goes
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

        // -- Typing -----------------------------------------------------------

        onKeydown(event) {
            if (event[MOD_KEY] && !event.altKey) {
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
                // execCommand is gone or refused — write directly and give up on undo
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
         * left fits — the hidden ones show up in the overflow menu instead.
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

                // Don't leave an empty group behind — its divider would hang there
                this.groups.forEach((group) => {
                    group.hidden = !group.querySelector('[data-command]:not([hidden])');
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
                // Hold the height the editor had grown to, so the box doesn't
                // jump around as the author flips between tabs
                this.previewEl.style.minHeight = this.source.offsetHeight + 'px';
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
                    flavor: this.config.flavor,
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
