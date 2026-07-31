/**
 * The highlighted layer sits behind the textarea and has to hold exactly the same
 * text, character for character — the moment the highlighter drops, adds or rewrites
 * one, the two layers stop lining up and the caret drifts away from the text.
 *
 * So that's what this checks: strip the markup back out of what the highlighter
 * produced, and it has to equal what went in.
 *
 *     node tests/js/highlight.test.js
 */

const fs = require('fs');
const path = require('path');

const editorPath = path.join(__dirname, '../../src/web/assets/editor/dist/editor.js');
const source = fs.readFileSync(editorPath, 'utf8');

// The editor is an IIFE that hands one class to the window; swap that for an export
// so the internals can be reached without a DOM
const body = source.replace(
    /window\.WahlbergEditor = WahlbergEditor;/,
    'module.exports = {highlightMarkdown};',
);

const exported = {exports: {}};
new Function('module', 'window', 'navigator', 'document', 'requestAnimationFrame', body)(
    exported, {}, {platform: 'MacIntel'}, {}, () => {},
);

const {highlightMarkdown} = exported.exports;

function textOf(html) {
    return html
        .replace(/<[^>]*>/g, '')
        .replace(/&lt;/g, '<')
        .replace(/&gt;/g, '>')
        .replace(/&amp;/g, '&')
        // Trailing spaces are deliberately rendered as non-breaking ones so they can't
        // hang — same character width, same position
        .replace(/ /g, ' ');
}

const cases = {
    'a bit of everything': `# Heading one
## Heading *two*

Some **bold** and _italic_ and ~~struck~~ text with \`inline code\`.
A snake_case_word must not go italic. 2 * 3 * 4 is maths.

- list item with [a link](https://example.com)
- another
  1. nested
  2. items

> quoted **text**
> more

\`\`\`php
<?php echo "hi" & 'bye'; ?>
# not a heading
\`\`\`

---

<https://autolink.test> and bare https://example.com/x?a=1&b=2
![img](/uploads/a.png)
| a | b |
|---|---|

\ttabbed line
unicode: café — 日本語 🎉`,
    'empty': '',
    'nothing but newlines': '\n\n\n',
    'unclosed markers': 'unclosed **bold and `code',
    'a wall of asterisks': '*'.repeat(200),
    'a wall of list markers': '- '.repeat(500),
    'html that must stay escaped': '<script>alert(1)</script> & <div>',
    'trailing whitespace on every line': 'one \ntwo  \nthree   \n',
    'formatting inside formatting': '**CHAPTER 1. _Loomings_.** and _Y**ea**h_ and ~~a **struck** bit~~',
    'a link label carrying emphasis': '[**bold** and _em_](https://example.com/**not**/_markup_)',
    'nesting deep enough to be silly': '**a _b ~~c `d` e~~ f_ g**',
    'nesting that never closes': '**outer _inner and `code',
};

let failures = 0;

function fail(message) {
    failures++;
    console.error('  x ' + message);
}

for (const [name, input] of Object.entries(cases)) {
    const roundTripped = textOf(highlightMarkdown(input));
    const expected = input + '\n';

    if (roundTripped === expected) {
        console.log('  - ' + name);
        continue;
    }

    let at = 0;
    while (at < expected.length && roundTripped[at] === expected[at]) {
        at++;
    }

    fail(`${name}: text changed at offset ${at}\n` +
        `      got  ${JSON.stringify(roundTripped.slice(Math.max(0, at - 20), at + 20))}\n` +
        `      want ${JSON.stringify(expected.slice(Math.max(0, at - 20), at + 20))}`);
}

// It also has to actually highlight something
const sample = highlightMarkdown(
    '# Hi\n**bold** _em_ ~~del~~ `code` [t](u)\n> quote\n- item\n```\nfence\n```',
);

for (const token of [
    'wh-heading', 'wh-strong', 'wh-em', 'wh-del', 'wh-code',
    'wh-link', 'wh-url', 'wh-quote', 'wh-fence', 'wh-mark',
]) {
    if (sample.includes(token)) {
        console.log('  - marks up ' + token);
    } else {
        fail('nothing was marked up as ' + token);
    }
}

// Formatting inside formatting is marked up as such, and code inside it isn't
const nested = {
    'emphasis inside strong': ['**a _b_ c**', /wh-strong.*wh-em/],
    'strong inside emphasis': ['_a **b** c_', /wh-em.*wh-strong/],
    'emphasis inside a heading': ['# a _b_', /wh-heading.*wh-em/],
    'a link label': ['[**a**](u)', /wh-link.*wh-strong/],
};

for (const [name, [input, pattern]] of Object.entries(nested)) {
    if (pattern.test(highlightMarkdown(input))) {
        console.log('  - ' + name);
    } else {
        fail(`${name}: ${JSON.stringify(input)} came out flat`);
    }
}

// A fence is literal, so what's inside one stays text however it's spelled
if (/wh-strong/.test(highlightMarkdown('`**bold**`'))) {
    fail('markers inside code were marked up');
} else {
    console.log('  - code is left alone');
}

// Every keystroke repaints the whole document, so this has to stay cheap
const big = '# Heading\n\nSome **text** with `code` and [links](https://a.b).\n'.repeat(2000);
const started = process.hrtime.bigint();
highlightMarkdown(big);
const ms = Number(process.hrtime.bigint() - started) / 1e6;

if (ms < 250) {
    console.log(`  - 8,000 lines in ${ms.toFixed(1)}ms`);
} else {
    fail(`8,000 lines took ${ms.toFixed(1)}ms, which is too slow to run on every keystroke`);
}

console.log(failures === 0 ? '\nOK' : `\n${failures} failed`);
process.exit(failures === 0 ? 0 : 1);
