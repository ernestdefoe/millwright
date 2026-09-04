<?php

namespace ErnestDefoe\Millwright\Tests\Unit;

use ErnestDefoe\Millwright\Apply\Applier;
use ErnestDefoe\Millwright\Apply\Journal;
use ErnestDefoe\Millwright\Apply\Rollback;
use ErnestDefoe\Millwright\Plan\Change;
use PHPUnit\Framework\TestCase;

/**
 * 🚨 The rule these protect: a rollback puts back the site's RECORD of itself,
 * not only its files.
 *
 * The plan phase runs `composer update --no-install`, which rewrites
 * composer.lock the moment it succeeds. A rollback that moves the directories
 * back and stops there leaves a lock claiming versions that are no longer on
 * disk — and nothing looks wrong. The damage lands at the NEXT update, when
 * Composer diffs against a lock describing work that was undone and plans around
 * packages it believes are already installed.
 */
class RollbackManifestTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/mw-manifest-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/install/vendor/acme/widget', 0775, true);
        mkdir($this->dir . '/staging/acme/widget', 0775, true);
        mkdir($this->dir . '/work', 0775, true);
        mkdir($this->dir . '/trash', 0775, true);

        file_put_contents($this->dir . '/install/vendor/acme/widget/old.php', 'old');
        file_put_contents($this->dir . '/staging/acme/widget/new.php', 'new');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function rollback(): Rollback
    {
        return new Rollback(
            $this->dir . '/install/vendor',
            $this->dir . '/trash',
            new Journal($this->dir . '/work/journal.jsonl'),
            $this->dir . '/install',
            $this->dir . '/work'
        );
    }

    private function applyOne(): void
    {
        (new Applier(
            $this->dir . '/install/vendor',
            $this->dir . '/staging',
            $this->dir . '/trash',
            new Journal($this->dir . '/work/journal.jsonl')
        ))->applyOne(new Change(Change::REPLACE, 'acme/widget', '1.0.0', '2.0.0'));
    }

    public function test_the_lock_goes_back_with_the_files(): void
    {
        file_put_contents($this->dir . '/work/composer.lock.before', '{"packages":[{"name":"acme/widget","version":"1.0.0"}]}');
        file_put_contents($this->dir . '/install/composer.lock', '{"packages":[{"name":"acme/widget","version":"2.0.0"}]}');

        $this->applyOne();
        $undone = $this->rollback()->run();

        $this->assertStringContainsString(
            '"version":"1.0.0"',
            file_get_contents($this->dir . '/install/composer.lock')
        );
        $this->assertContains('restored composer.lock', $undone, 'and it says so, rather than doing it quietly');
        $this->assertFileExists($this->dir . '/install/vendor/acme/widget/old.php');
    }

    public function test_composer_json_goes_back_too_when_an_install_changed_it(): void
    {
        // `composer require` edits composer.json as well as the lock, so an
        // install that is rolled back must not leave the package still required.
        file_put_contents($this->dir . '/work/composer.lock.before', '{"packages":[]}');
        file_put_contents($this->dir . '/work/composer.json.before', '{"require":{}}');
        file_put_contents($this->dir . '/install/composer.lock', '{"packages":[{"name":"acme/widget"}]}');
        file_put_contents($this->dir . '/install/composer.json', '{"require":{"acme/widget":"^2.0"}}');

        $this->applyOne();
        $this->rollback()->run();

        $this->assertSame('{"require":{}}', file_get_contents($this->dir . '/install/composer.json'));
    }

    public function test_a_run_that_never_touched_composer_json_leaves_it_alone(): void
    {
        /*
         * An update saves only the lock, so composer.json.before is absent — its
         * absence is the normal case and must not be read as "restore nothing"
         * or as an error.
         */
        file_put_contents($this->dir . '/work/composer.lock.before', '{"packages":[]}');
        file_put_contents($this->dir . '/install/composer.json', '{"require":{"acme/widget":"^1.0"}}');

        $this->applyOne();
        $undone = $this->rollback()->run();

        $this->assertSame('{"require":{"acme/widget":"^1.0"}}', file_get_contents($this->dir . '/install/composer.json'));
        $this->assertNotContains('restored composer.json', $undone);
    }

    public function test_restoring_twice_is_harmless(): void
    {
        // The journal makes every step repeatable, and this one is no exception:
        // a rollback interrupted after the files and before the lock is resumed
        // by simply running it again.
        file_put_contents($this->dir . '/work/composer.lock.before', '{"packages":[{"version":"1.0.0"}]}');
        file_put_contents($this->dir . '/install/composer.lock', '{"packages":[{"version":"2.0.0"}]}');

        $this->applyOne();
        $this->rollback()->run();
        $this->rollback()->run();

        $this->assertStringContainsString('1.0.0', file_get_contents($this->dir . '/install/composer.lock'));
    }
}
