/**
 * What happens after a snippet is picked from a Markdown field's `/` menu.
 *
 * Craft's text expander inserts the snippet's text and fires `craft-select` with the
 * option it inserted. Wahlberg's options say whether the snippet is inline, and
 * carry its stops, already measured from the start of the text (see
 * `Snippets::compile()`). So this only has to find where the text landed, move a
 * block (anything not inline) onto lines of its own, and walk the author through the stops.
 *
 * More than one stop starts a run: Tab moves on, Shift+Tab back, Escape hands Tab
 * back. Tab is only borrowed while a run lasts. It's how you leave a field, and one
 * that kept it would be one you couldn't get out of. The same rules as 1.x.
 */
(() => {
  if (window.wahlbergTabStops) {
    return;
  }
  window.wahlbergTabStops = true;

  const isMac = navigator.platform.toLowerCase().includes('mac');

  /** The run in progress, if any. There's only ever one: it ends on blur. */
  let run = null;

  /**
   * Where a snippet's stops end up after an edit, so Tab keeps landing on them as
   * the author fills one in.
   *
   * Text typed at the end of the stop being filled in belongs to that stop, not to
   * whatever begins where it used to end, so what follows is decided by the stop's
   * old end and not by the caret.
   *
   * `at` is the stop being filled in, `edit` where the change began, `caret` where
   * it left the caret, `delta` how much longer the value got.
   */
  function shiftStops(stops, at, edit, caret, delta) {
    const current = stops[at];
    const inside = !!current && edit >= current.from && edit <= current.to;
    const boundary = inside ? current.to : edit;

    return stops.map((stop) => {
      if (inside && stop === current) {
        return {from: stop.from, to: Math.max(stop.from, caret)};
      }

      if (stop.from >= boundary) {
        return {from: stop.from + delta, to: stop.to + delta};
      }

      return stop;
    });
  }

  /**
   * A block picked mid-line (a quote, a table) gets a blank line either side, which
   * Markdown needs to read it as one. The spaces the author typed before the `/`
   * go, so the line it came from doesn't end in them. Returns where the snippet
   * now starts.
   *
   * One edit, through `execCommand` like the expander's, so it stays undoable.
   */
  function placeAsBlock(textarea, from, to) {
    const value = textarea.value;
    const before = value.slice(0, from).replace(/[ \t]+$/, '');
    const after = value.slice(to).replace(/^[ \t]+/, '');
    const prefix = before ? '\n'.repeat(Math.max(0, 2 - before.match(/\n*$/)[0].length)) : '';
    const suffix = after ? '\n'.repeat(Math.max(0, 2 - after.match(/^\n*/)[0].length)) : '';
    const start = before.length;
    const finish = value.length - after.length;

    if (!prefix && !suffix && start === from && finish === to) {
      return from;
    }

    const replacement = prefix + value.slice(from, to) + suffix;
    textarea.setSelectionRange(start, finish);

    if (!document.execCommand('insertText', false, replacement)) {
      textarea.setRangeText(replacement, start, finish, 'end');
      textarea.dispatchEvent(new Event('input', {bubbles: true}));
    }

    return start + prefix.length;
  }

  function end() {
    if (!run) {
      return;
    }

    run.textarea.removeEventListener('input', onInput);
    run.textarea.removeEventListener('blur', end);
    run = null;
  }

  /** Off the end and the run is over, with the caret where the last stop left it. */
  function goTo(index) {
    if (index >= run.stops.length) {
      end();
      return;
    }

    run.at = Math.max(0, index);
    const stop = run.stops[run.at];

    // Selected rather than pointed at, so a default gets typed over. A bare stop
    // is an empty range, which is a caret
    run.textarea.setSelectionRange(stop.from, stop.to);
  }

  function onInput() {
    const textarea = run.textarea;
    const delta = textarea.value.length - run.length;
    run.length = textarea.value.length;

    if (delta) {
      const caret = textarea.selectionStart;

      // An insertion ends at the caret, a deletion leaves it where it began
      run.stops = shiftStops(run.stops, run.at, caret - Math.max(delta, 0), caret, delta);
      run.to += delta;
    }

    leaveIfOutside();
  }

  /** Writing somewhere else ends it, or Tab would pull the author back in. */
  function leaveIfOutside() {
    if (!run) {
      return;
    }

    const at = run.textarea.selectionStart;

    if (at < run.from || at > run.to) {
      end();
    }
  }

  // Capture phase, so the run sees Tab before OverType indents with it
  window.addEventListener(
    'keydown',
    (event) => {
      if (!run || event.target !== run.textarea) {
        return;
      }

      const mod = isMac ? event.metaKey : event.ctrlKey;

      if (event.key === 'Tab' && !event.altKey && !mod) {
        event.preventDefault();
        event.stopImmediatePropagation();
        goTo(run.at + (event.shiftKey ? -1 : 1));
        return;
      }

      // The way out of a run the author didn't want. Stopped, so it doesn't also
      // close a slideout the field is in
      if (event.key === 'Escape') {
        event.preventDefault();
        event.stopImmediatePropagation();
        end();
      }
    },
    true,
  );

  document.addEventListener('selectionchange', () => {
    if (run && document.activeElement === run.textarea) {
      leaveIfOutside();
    }
  });

  document.addEventListener('craft-select', (event) => {
    const snippet = event.detail?.option?.data?.wahlberg;

    if (!snippet) {
      return;
    }

    // The expander points at the field's textarea by id
    const expander = event.composedPath()[0];
    const root = expander.getRootNode?.() ?? document;
    const textarea = root.getElementById?.(expander.getAttribute?.('for'));

    if (!(textarea instanceof HTMLTextAreaElement)) {
      return;
    }

    end();

    // The expander leaves the caret after what it inserted. If the field's limit
    // cut it short, the snippet isn't what's there, so leave it be
    const text = event.detail.option.value;
    let to = textarea.selectionEnd;
    let from = to - text.length;

    if (from < 0 || textarea.value.slice(from, to) !== text) {
      return;
    }

    if (!snippet.inline) {
      from = placeAsBlock(textarea, from, to);
      to = from + text.length;
    }

    const stops = Array.isArray(snippet.stops) ? snippet.stops : [];
    const absolute = stops.map(([start, finish]) => ({from: from + start, to: from + finish}));

    // None leaves the caret after the snippet, and one is where it goes. Neither
    // needs a run
    if (absolute.length < 2) {
      const caret = absolute[0] ?? {from: to, to};
      textarea.setSelectionRange(caret.from, caret.to);
      return;
    }

    run = {
      textarea,
      stops: absolute,
      at: 0,
      from,
      to,
      // What the value measured when the stops were last correct
      length: textarea.value.length,
    };

    textarea.addEventListener('input', onInput);
    textarea.addEventListener('blur', end);
    goTo(0);
  });
})();
