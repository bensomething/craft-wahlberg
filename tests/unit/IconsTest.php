<?php

namespace bensomething\wahlberg\tests\unit;

use bensomething\wahlberg\helpers\Icons;
use bensomething\wahlberg\tests\TestCase;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Tabler Icons isn’t installed in this suite, and that’s the point: what these tests
 * pin down is that the integration stays out of the way when it isn’t there.
 *
 * It has to do so without asking Craft anything, which is what makes it testable at
 * all — {@see \bensomething\wahlberg\tests\support\Application} has no plugins
 * service, so reaching for one throws an unknown-method error. Any test here that
 * starts failing that way is telling you a guard has been reordered.
 */
class IconsTest extends TestCase
{
    #[TestDox('an icon token is left as typed when Tabler Icons isn’t installed')]
    public function testLeavesTokensAloneWhenPluginIsMissing(): void
    {
        $html = '<p>A star <span>{icon:star}</span> here.</p>';

        self::assertSame($html, Icons::process($html));
    }

    #[TestDox('the integration reports itself unavailable without asking Craft')]
    public function testUnavailableWithoutTheApplication(): void
    {
        self::assertFalse(Icons::available());
    }

    #[TestDox('content with no icon tokens is returned untouched, without asking Craft')]
    public function testShortCircuitsWithoutTokens(): void
    {
        // The short-circuit is the subject: were it not there, this would reach for the
        // plugins service, which this suite has no honest way to provide
        $html = '<p>Nothing to resolve here.</p>';

        self::assertSame($html, Icons::process($html));
    }

    #[TestDox('a token that only looks like one is not treated as an icon')]
    public function testIgnoresNearMisses(): void
    {
        $html = '<p>{icons:star} and {icon} and {entry:1:url}</p>';

        self::assertSame($html, Icons::process($html));
    }
}
