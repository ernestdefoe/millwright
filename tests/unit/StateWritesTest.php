<?php

namespace ErnestDefoe\Millwright\Tests\Unit;

use ErnestDefoe\Millwright\Apply\Tree;
use ErnestDefoe\Millwright\Host\StateFile;
use ErnestDefoe\Millwright\Tests\unit\EnglishTranslator;
use ErnestDefoe\Millwright\Tests\unit\Fakes;
use ErnestDefoe\Millwright\Work\ComposerRunner;
use ErnestDefoe\Millwright\Work\MigrationLedger;
use ErnestDefoe\Millwright\Work\Planner;
use ErnestDefoe\Millwright\Work\WorkDir;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * State a run cannot go on without is written whole or the run stops.
 */
class StateWritesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/mw-state-'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        @chmod($this->dir.'/storage/millwright/runs/r1', 0775);
        Tree::delete($this->dir);
    }

    /**
     * A run whose package list was not written used to read back as an empty
     * list, and an update of one extension became `composer update` of every
     * package on the site.
     */
    public function test_a_run_that_cannot_record_its_packages_does_not_start(): void
    {
        $this->skipIfRoot();
        $work = (new WorkDir(EnglishTranslator::make(), $this->dir.'/storage', 'r1'))->create();
        chmod($work->root(), 0555);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not write');
        $work->remember(['acme/widget']);
    }

    public function test_the_plan_refuses_an_empty_package_list(): void
    {
        $composer = new ComposerRunner(EnglishTranslator::make(), $this->dir, __FILE__, $this->dir.'/home');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not say which packages');
        (new Planner(Fakes::run($this->dir, $composer, [], installPath: $this->dir)))->resolve();
    }

    /** Without a baseline, every migration ever run would look new and undo would reverse them all. */
    public function test_migrations_are_never_recorded_without_a_baseline(): void
    {
        mkdir($this->dir.'/work', 0775, true);
        $forum = Fakes::forum();

        $this->expectException(RuntimeException::class);
        (new MigrationLedger(EnglishTranslator::make(), $this->dir.'/work'))->after($forum->db, $forum->extensions, $this->dir.'/vendor', $forum->log);
    }

    public function test_a_state_write_is_whole_or_absent(): void
    {
        $this->assertTrue(StateFile::write($this->dir.'/a/b.json', ['x' => 1]));
        $this->assertSame(['x' => 1], json_decode((string) file_get_contents($this->dir.'/a/b.json'), true));
        $this->assertSame(['b.json'], array_values(array_diff(scandir($this->dir.'/a') ?: [], ['.', '..'])));
    }

    private function skipIfRoot(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root ignores directory permissions');
        }
    }
}
