<?php

namespace ErnestDefoe\Millwright\Tests\Unit;

use ErnestDefoe\Millwright\Apply\Applier;
use ErnestDefoe\Millwright\Apply\Journal;
use ErnestDefoe\Millwright\Tests\unit\EnglishTranslator;
use ErnestDefoe\Millwright\Work\ComposerRunner;
use ErnestDefoe\Millwright\Work\ComposerSteps;
use ErnestDefoe\Millwright\Work\Fetcher;
use ErnestDefoe\Millwright\Tests\unit\Fakes;
use ErnestDefoe\Millwright\Work\SiteCheck;
use PHPUnit\Framework\TestCase;

/**
 * When the web server will have re-read the update's files.
 *
 * Timed from the autoloader alone, an update that changes no classes
 * (2.0.0-rc.8 → 2.0.0) found an old timestamp, skipped the wait, and was
 * judged while php-fpm still ran the old code (wowcraft, 2026-10-09).
 */
class CodeLiveAtTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/mw-live-'.bin2hex(random_bytes(6));
        mkdir($this->dir.'/project/vendor/composer', 0777, true);
        mkdir($this->dir.'/work', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    private function liveAt(int $freq): int
    {
        $run = Fakes::run(
            $this->dir,
            new ComposerRunner(EnglishTranslator::make(), $this->dir.'/project', __FILE__, $this->dir.'/home'),
            vendorPath: $this->dir.'/project/vendor',
        );

        return (new SiteCheck($run, new \GuzzleHttp\Client()))->codeLiveAt($freq);
    }

    public function test_an_untouched_autoloader_does_not_end_the_wait_early(): void
    {
        $composer = $this->dir.'/project/vendor/composer';
        file_put_contents($composer.'/autoload_static.php', '<?php');
        touch($composer.'/autoload_static.php', time() - 86400 * 3);   // classes unchanged: days old
        file_put_contents($composer.'/installed.json', '{}');           // rewritten by this run

        $this->assertGreaterThanOrEqual(time() + 60, $this->liveAt(60));
    }

    public function test_it_still_counts_from_the_autoloader_when_that_is_newer(): void
    {
        $composer = $this->dir.'/project/vendor/composer';
        file_put_contents($composer.'/installed.json', '{}');
        touch($composer.'/installed.json', time() - 600);
        file_put_contents($composer.'/autoload_static.php', '<?php');

        $this->assertGreaterThanOrEqual(time() + 60, $this->liveAt(60));
    }
}
