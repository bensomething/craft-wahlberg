/**
 * Switching between Write and Preview keeps the author's place by matching blocks:
 * the block at the top of one pane is the element to put at the top of the other.
 * That only works while the source is split the way the parser splits it, so this
 * checks where the splits land.
 *
 * The offsets matter as much as the count — each one is fed to a marker dropped into
 * a copy of the textarea, and a boundary landing mid-line would measure the wrong
 * row. So every case asserts the line each block starts on, taken by slicing the
 * source at the offset the splitter returned.
 *
 *     node tests/js/blocks.test.js
 */

// The editor hands its class to the window on the way past, so there has to be one
global.window = {};

const {sourceBlocks} = require('../../src/web/assets/editor/resources/editor.js');

let failures = 0;

function fail(message) {
    failures++;
    console.error('  ✗ ' + message);
}

const cases = [
    {
        name: 'a blank line starts a new block',
        source: 'One.\n\nTwo.\n\nThree.',
        blocks: ['One.', 'Two.', 'Three.'],
    },
    {
        name: 'lines that run on are one block',
        source: '- one\n- two\n- three',
        blocks: ['- one'],
    },
    {
        name: 'a blank line inside a fence is not a break',
        source: '```php\n$a = 1;\n\n$b = 2;\n```\n\nAfter.',
        blocks: ['```php', 'After.'],
    },
    {
        name: 'a fence left open swallows the rest',
        source: 'Before.\n\n```\ncode\n\nmore code',
        blocks: ['Before.', '```'],
    },
    {
        name: 'a fence opening straight after a paragraph stays with it',
        source: 'Text.\n```\ncode\n```',
        blocks: ['Text.'],
    },
    {
        name: 'a heading is its own block without a blank line before it',
        source: 'A paragraph.\n## A heading\nAnd more text.',
        blocks: ['A paragraph.', '## A heading', 'And more text.'],
    },
    {
        name: 'a `#` inside a fence is not a heading',
        source: '```\n# not a heading\n\n# nor this\n```',
        blocks: ['```'],
    },
    {
        name: 'leading blank lines belong to nothing',
        source: '\n\n\nFirst.',
        blocks: ['First.'],
    },
    {
        name: 'trailing blank lines add no block',
        source: 'Only.\n\n\n',
        blocks: ['Only.'],
    },
    {
        name: 'several blank lines are one break',
        source: 'One.\n\n\n\nTwo.',
        blocks: ['One.', 'Two.'],
    },
    {
        name: 'a quote runs on like anything else',
        source: '> quoted\n> more\n\nOut.',
        blocks: ['> quoted', 'Out.'],
    },
    {
        name: 'nothing at all',
        source: '',
        blocks: [],
    },
    {
        name: 'whitespace only',
        source: '   \n\n  \n',
        blocks: [],
    },
];

for (const {name, source: markdown, blocks} of cases) {
    const offsets = sourceBlocks(markdown);

    // Slicing at the offset has to land at the start of a line, or the marker
    // measuring it would sit mid-row
    const found = offsets.map((offset) => markdown.slice(offset).split('\n')[0]);
    const starts = offsets.every((offset) => offset === 0 || markdown[offset - 1] === '\n');

    if (!starts) {
        fail(`${name}: a block starts mid-line`);
        continue;
    }

    if (JSON.stringify(found) === JSON.stringify(blocks)) {
        console.log('  - ' + name);
        continue;
    }

    fail(`${name}\n      got  ${JSON.stringify(found)}\n      want ${JSON.stringify(blocks)}`);
}

// The offsets have to come back in order, or the search for the block at a given
// height would stop at the wrong one
const messy = sourceBlocks('# One\n\ntext\n\n```\n\n```\n\n## Two\nmore');

if (messy.every((offset, i) => i === 0 || offset > messy[i - 1])) {
    console.log('  - blocks come back in order');
} else {
    fail('blocks came back out of order: ' + JSON.stringify(messy));
}

// It runs on every tab switch, over whatever the author has written
const big = '# Heading\n\nSome text with `code` and [links](https://a.b).\n\n'.repeat(4000);
const started = process.hrtime.bigint();
const count = sourceBlocks(big).length;
const ms = Number(process.hrtime.bigint() - started) / 1e6;

if (count !== 8000) {
    fail(`expected 8,000 blocks in the big document, got ${count}`);
} else if (ms < 100) {
    console.log(`  - 8,000 blocks in ${ms.toFixed(1)}ms`);
} else {
    fail(`8,000 blocks took ${ms.toFixed(1)}ms`);
}

console.log(failures === 0 ? '\nOK' : `\n${failures} failed`);
process.exit(failures === 0 ? 0 : 1);
