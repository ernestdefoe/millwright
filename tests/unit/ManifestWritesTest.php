<?php

namespace ErnestDefoe\Millwright\Tests\Unit;

use ErnestDefoe\Millwright\Apply\Tree;
use PHPUnit\Framework\TestCase;

/**
 * composer.json and composer.lock are only ever replaced whole.
 *
 * A plain copy() over the live file truncates it first, so a kill or a full
 * disk part-way leaves a manifest Composer cannot read, on the forum the run
 * was meant to protect.
 */
class ManifestWritesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/mw-manifest-'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        @chmod($this->dir, 0775);
        Tree::delete($this->dir);
    }

    public function test_a_replace_that_cannot_finish_leaves_the_old_file_whole(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root ignores directory permissions');
        }

        file_put_contents($this->dir.'/composer.lock', 'OLD');
        file_put_contents($this->dir.'/saved', 'NEW');
        chmod($this->dir, 0555);   // nothing new can be created beside it

        $this->assertFalse(Tree::replaceFile($this->dir.'/saved', $this->dir.'/composer.lock'));
        $this->assertSame('OLD', file_get_contents($this->dir.'/composer.lock'));
        $this->assertSame(['composer.lock', 'saved'], array_values(array_diff(scandir($this->dir) ?: [], ['.', '..'])));
    }

    public function test_nothing_copies_straight_over_a_manifest(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/src', \FilesystemIterator::SKIP_DOTS)) as $file) {
            foreach (file($file->getPathname()) ?: [] as $n => $line) {
                // The destination is the live forum: the second argument.
                if (preg_match('/(?<![\w:>])@?copy\([^,]+,\s*(\$live\b|\$[a-z>-]*installPath)/', $line)) {
                    $offenders[] = substr($file->getPathname(), strlen($root) + 1).':'.($n + 1);
                }
            }
        }

        $this->assertSame([], $offenders, 'Use Tree::replaceFile() so the live manifest is never half-written.');
    }
}
