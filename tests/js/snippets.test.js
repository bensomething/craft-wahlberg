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
    'module.exports = {snippetStops, shiftStops};',
);

const exported = {exports: {}};
new Function('module', 'window', 'navigator', 'document', 'requestAnimationFrame', body)(
    exported, {}, {platform: 'MacIntel'}, {}, () => {},
);

const {snippetStops, shiftStops} = exported.exports;

let failures = 0;

function fail(message) {
    failures++;
    console.error('  ✗ ' + message);
}

/**
 * Stops are written as `[from, to]` pairs, and as a bare number where the two are
 * the same — a stop with no default is an empty range, which is a caret.
 */
function check(name, input, text, stops) {
    const got = snippetStops(input);
    const want = stops.map((stop) => (Array.isArray(stop) ? stop : [stop, stop]));
    const found = got.stops.map((stop) => [stop.from, stop.to]);

    if (got.text !== text) {
        fail(`${name}: text\n      got  ${JSON.stringify(got.text)}\n      want ${JSON.stringify(text)}`);
        return;
    }

    if (JSON.stringify(found) !== JSON.stringify(want)) {
        fail(`${name}: stops\n      got  ${JSON.stringify(found)}\n      want ${JSON.stringify(want)}`);
        return;
    }

    // A stop has to point at text that's actually there, and not backwards
    for (const [from, to] of found) {
        if (from < 0 || to > got.text.length || from > to) {
            fail(`${name}: [${from}, ${to}] doesn't fit ${JSON.stringify(got.text)}`);
            return;
        }
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

// -- Defaults -------------------------------------------------------------------

check('a default stays in the text, with the stop over it', '${1:Name}', 'Name', [[0, 4]]);
check('braces without a default are a bare stop', 'a${1}b', 'ab', [1]);
check('an empty default is a bare stop too', 'a${1:}b', 'ab', [1]);

check(
    'defaults and bare stops together',
    '[${1:text}]($0)',
    '[text]()',
    [[1, 5], 7],
);

check(
    'the numbering still decides the order',
    '${2:second} ${1:first}',
    'second first',
    [[7, 12], [0, 6]],
);

check('$0 can carry one as well', 'x${0:here}', 'xhere', [[1, 5]]);

check(
    'a default is text, so a marker inside one survives to be substituted',
    '${1:$SELECTION}',
    '$SELECTION',
    [[0, 10]],
);

check(
    'a callout with something to read',
    '> **${1:Note}**\n> ${0:…}\n',
    '> **Note**\n> …\n',
    [[4, 8], [13, 14]],
);

// An unclosed brace isn't a stop, and mustn't eat the rest of the body
check('an unclosed brace is text', 'a${1:b', 'a${1:b', []);

// -- Keeping the stops in place as one is filled in -----------------------------

/**
 * Types `typed` over the stop at `at`, and reports where the stops end up.
 * `stops` and the result are `[from, to]` pairs.
 */
function types(stops, at, typed) {
    const ranges = stops.map((stop) => (Array.isArray(stop) ? {from: stop[0], to: stop[1]} : {from: stop, to: stop}));
    const current = ranges[at];

    // Replacing what the stop covers: the value loses its width and gains the text
    const delta = typed.length - (current.to - current.from);
    const caret = current.from + typed.length;

    return shiftStops(ranges, at, caret - Math.max(delta, 0), caret, delta)
        .map((stop) => [stop.from, stop.to]);
}

function shifted(name, stops, at, typed, want) {
    const got = types(stops, at, typed);
    const expected = want.map((stop) => (Array.isArray(stop) ? stop : [stop, stop]));

    if (JSON.stringify(got) === JSON.stringify(expected)) {
        console.log('  - ' + name);
        return;
    }

    fail(`${name}\n      got  ${JSON.stringify(got)}\n      want ${JSON.stringify(expected)}`);
}

// `| ${1:Column} | $2 |` — the stop with the default, then a bare one after it.
// Shortening the default must not leave the bare stop covering the difference
shifted('a bare stop after a shortened default stays a point', [[2, 8], 11], 0, 'ab', [[2, 4], 7]);
shifted('and after a lengthened one', [[2, 8], 11], 0, 'Much longer', [[2, 13], 16]);
shifted('and after one typed over exactly', [[2, 8], 11], 0, 'Ledge', [[2, 7], 10]);
shifted('and after one emptied', [[2, 8], 11], 0, '', [[2, 2], 5]);

// The stop being filled in ends where the caret does, so going back selects what
// was typed rather than what the default used to measure
shifted('the current stop follows what replaced it', [[2, 8]], 0, 'Hi', [[2, 4]]);

// A stop that starts exactly where the edited one ended still belongs after it
shifted('an adjacent stop moves with the text', [[2, 4], 4], 0, 'X', [[2, 3], 3]);
shifted('an adjacent stop after growth', [[2, 4], 4], 0, 'XYZ', [[2, 5], 5]);

// Anything before the edit is untouched
shifted('a stop before the edit stays put', [0, [2, 8], 11], 1, 'x', [0, [2, 3], 6]);

// Order is visit order, not document order, so a later stop can be earlier in text
shifted('a stop earlier in the text than the one being filled', [[10, 14], [2, 4]], 0, 'ab', [[10, 12], [2, 4]]);

// A second edit, typing on after the default was replaced
let after = types([[2, 8], 11], 0, 'N');
after = shiftStops(
    after.map((stop) => ({from: stop[0], to: stop[1]})),
    0, 3, 4, 1,
).map((stop) => [stop.from, stop.to]);

if (JSON.stringify(after) === JSON.stringify([[2, 4], [7, 7]])) {
    console.log('  - typing on after the default was replaced');
} else {
    fail(`typing on after the default was replaced\n      got  ${JSON.stringify(after)}\n      want [[2,4],[7,7]]`);
}

console.log(failures === 0 ? '\nOK' : `\n${failures} failed`);
process.exit(failures === 0 ? 0 : 1);
