<?php

namespace ErnestDefoe\Millwright\Tests\Unit;

use ErnestDefoe\Millwright\Plan\CoreUpgrade;
use PHPUnit\Framework\TestCase;

/**
 * The rule these protect: the question "what would upgrading Flarum do to my
 * extensions" is answered BEFORE anything is pressed, per extension, by name.
 *
 * Composer's answer to the same question is a refusal at the end of a long wait,
 * written in terms of packages and version constraints, from which somebody then
 * has to work out which of their thirty extensions is the problem.
 */
class CoreUpgradeTest extends TestCase
{
    private function ext(string $name, string $version, ?string $requires): array
    {
        $row = ['name' => $name, 'version' => $version, 'type' => 'flarum-extension'];

        if ($requires !== null) {
            $row['require'] = ['flarum/core' => $requires];
        }

        return $row;
    }

    public function test_the_lock_alone_answers_for_extensions_that_already_fit(): void
    {
        /*
         * The important property is not the verdict, it is that NOTHING was
         * asked. Doing one Packagist call per extension took 28 seconds against
         * a real forum's 58 extensions — an endpoint that cannot finish inside
         * thirty seconds, built into the screen whose whole promise is that
         * nothing takes longer than one request.
         */
        $r = (new CoreUpgrade())->preflight([
            $this->ext('acme/a', '2.0.0', '^2.0'),
            $this->ext('acme/b', '1.4.0', '^1.8 || ^2.0'),
        ], '2.1.0');

        $this->assertSame([], $r['pending'], 'nothing that fits should cost a network call');
        $this->assertSame(0, $r['blocked']);
        $this->assertSame(['ready', 'ready'], array_column($r['verdicts'], 'state'));
    }

    public function test_extensions_that_do_not_fit_are_handed_back_to_be_asked_about(): void
    {
        $r = (new CoreUpgrade())->preflight([
            $this->ext('acme/old', '1.0.0', '^1.8'),
            $this->ext('acme/fine', '2.0.0', '^2.0'),
        ], '2.1.0');

        $this->assertSame(['acme/old'], $r['pending']);
        $this->assertSame('unknown', $r['verdicts'][0]['state'], 'unresolved is unknown, never assumed');
    }

    public function test_things_that_are_not_extensions_are_not_in_the_list(): void
    {
        // A forum's lock holds two hundred libraries. They are not the question.
        $r = (new CoreUpgrade())->preflight([
            ['name' => 'psr/log', 'version' => '3.0.0', 'type' => 'library'],
            ['name' => 'flarum/core', 'version' => '2.0.0', 'type' => 'flarum-core'],
            $this->ext('acme/a', '2.0.0', '^2.0'),
        ], '2.1.0');

        $this->assertSame(['acme/a'], array_column($r['verdicts'], 'package'));
    }

    public function test_an_extension_declaring_no_core_constraint_is_asked_about(): void
    {
        // Rare, and the honest response is to ask rather than to assume either way.
        $r = (new CoreUpgrade())->preflight([$this->ext('acme/loose', '1.0.0', null)], '2.1.0');

        $this->assertSame(['acme/loose'], $r['pending']);
        $this->assertNull($r['verdicts'][0]['requires']);
    }

    public function test_a_release_candidate_core_admits_a_caret_two_extension(): void
    {
        // Every Flarum 2 forum is on an rc today, and plain semver would call
        // 2.0.0-rc.8 less than 2.0.0 and block the lot.
        $r = (new CoreUpgrade())->preflight([$this->ext('acme/a', '1.0.0', '^2.0')], '2.0.0-rc.8');

        $this->assertSame('ready', $r['verdicts'][0]['state']);
    }
}
