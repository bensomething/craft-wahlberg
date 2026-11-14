<?php

declare(strict_types=1);

namespace bensomething\wahlberg\tests\Feature;

use bensomething\wahlberg\Snippets;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** How a snippet body turns into text and stops. The same rules as 1.x's editor. */
final class CompileTest extends TestCase
{
    /** @return iterable<string, array{string, string, list<array{int, int}>}> */
    public static function bodies(): iterable
    {
        yield 'no markers' => ['Plain', 'Plain', []];
        yield 'caret only' => ['A $0 B', 'A  B', [[2, 2]]];
        yield 'defaults are selected' => ['${1:Name} is ${2:here}', 'Name is here', [[0, 4], [8, 12]]];
        yield '$0 goes last' => ['$0 then $1', ' then ', [[6, 6], [0, 0]]];
        yield 'a repeated number goes by position' => ['$1 and $1', ' and ', [[0, 0], [5, 5]]];
        yield 'braced without a default' => ['a${1}b', 'ab', [[1, 1]]];
        yield 'a default runs to the first brace' => ['${1:a{b}c}', 'a{bc}', [[0, 3]]];
        yield '$SELECTION goes' => ['**$SELECTION**$0', '****', [[4, 4]]];
        yield 'offsets count UTF-16 units' => ['😀 ${1:é}', '😀 é', [[3, 4]]];
    }

    /** @param list<array{int, int}> $stops */
    #[DataProvider('bodies')]
    public function test_it_compiles(string $body, string $text, array $stops): void
    {
        $this->assertSame(['text' => $text, 'stops' => $stops], Snippets::compile($body));
    }
}
