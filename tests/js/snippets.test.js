/**
 * A snippet body carries stops — `$1` through `$9`, then `$0` — and the editor takes
 * them out before the text goes in, remembering where each one was so Tab can visit
 * them in turn.
 *
 * Two things have to hold, and both are easy to break: the text left behind is the
 * body minus the markers and nothing else, and the offsets point at the characters
 * the markers were sitting in front of.
 *
 *     node tests/js/snippets.test.js
 */

const fs = require('fs');
const path = require('path');

const editorPath = path.join(__dirname, '../../src/web/assets/editor/dist/editor.js');
const source = fs.readFileSync(editorPath, 'utf8');

// The editor is an IIFE that hands one class to the window; swap that for an export
// so the internals can be reached without a DOM
const body = source.replace(
    /window\.WahlbergEditor = WahlbergEditor;/,
    'module.exports = {snippetStops};',
);

const exported = {exports: {}};
new Function('module', 'window', 'navigator', 'document', 'requestAnimationFrame', body)(
    exported, {}, {platform: 'MacIntel'}, {}, () => {},
);

const {snippetStops} = exported.exports;

let failures = 0;

function fail(message) {
    failures++;
    console.error('  ✗ ' + message);
}

function check(name, input, text, stops) {
    const got = snippetStops(input);

    if (got.text !== text) {
        fail(`${name}: text\n      got  ${JSON.stringify(got.text)}\n      want ${JSON.stringify(text)}`);
        return;
    }

    if (JSON.stringify(got.stops) !== JSON.stringify(stops)) {
        fail(`${name}: stops\n      got  ${JSON.stringify(got.stops)}\n      want ${JSON.stringify(stops)}`);
        return;
    }

    console.log('  - ' + name);
}

// The body every field had before stops existed has to behave as it did
check('a lone $0 is the caret, as it always was', 'a$0b', 'ab', [1]);
check('no marker at all leaves no stops', 'plain', 'plain', []);

check('numbered stops come in order', '$1a$2b$3', 'ab', [0, 1, 2]);
check('$0 goes last however early it appears', '$0a$1b$2', 'ab', [1, 2, 0]);
check('out of order in the body, in order out of it', '$3a$1b$2', 'ab', [1, 2, 0]);

// Markers are taken out, not replaced, so the text has to close up behind them
check('markers leave nothing behind', 'x$1y$0z', 'xyz', [1, 2]);
check('two at the same spot', '$1$2ab', 'ab', [0, 0]);
check('a repeated number keeps the earlier one first', '$1a$1b', 'ab', [0, 1]);

// A stop at either end
check('a stop at the very start', '$1abc', 'abc', [0]);
check('a stop at the very end', 'abc$0', 'abc', [3]);

// Realistic bodies
check(
    'a table',
    '| $1 | $2 |\n| --- | --- |\n| $0 |  |\n',
    '|  |  |\n| --- | --- |\n|  |  |\n',
    // Row one is 8 characters with its newline, row two 14, so the third row's
    // first cell opens at 22 and `$0` sat two in
    [2, 5, 24],
);

check(
    'a callout',
    '> **$1**\n> $0\n',
    '> ****\n> \n',
    [4, 9],
);

// `$SELECTION` is substituted after this runs, so it has to survive untouched
check('the selection marker is left alone', '$1$SELECTION$0', '$SELECTION', [0, 10]);

// A `$` that isn't a stop
check('a dollar with no digit is text', 'costs $5.00 and $x', 'costs .00 and $x', [6]);

// Every offset has to land inside the text it points into
const {text, stops} = snippetStops('$1one$2two$3three$0');

if (stops.every((stop) => stop >= 0 && stop <= text.length)) {
    console.log('  - every stop lands inside the text');
} else {
    fail(`stops outside the text: ${JSON.stringify(stops)} in ${JSON.stringify(text)}`);
}

console.log(failures === 0 ? '\nOK' : `\n${failures} failed`);
process.exit(failures === 0 ? 0 : 1);
