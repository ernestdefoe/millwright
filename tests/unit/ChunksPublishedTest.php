<?php

namespace ErnestDefoe\Millwright\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Flarum only publishes a lazily loaded chunk when its folder is registered
 * with Extend\Frontend::jsDirectory(). The release notes window was built as a
 * chunk and never registered, so "Learn more" 404'd on every forum (ClaudiusH).
 */
class ChunksPublishedTest extends TestCase
{
    public function test_every_built_chunk_folder_is_registered(): void
    {
        $root = dirname(__DIR__, 2);
        $extend = (string) file_get_contents($root.'/extend.php');

        foreach (glob($root.'/js/dist/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $frontend = basename($dir);

            $this->assertStringContainsString(
                "->jsDirectory(__DIR__.'/js/dist/$frontend')",
                $extend,
                "js/dist/$frontend holds chunks, but extend.php does not register it, so Flarum never publishes them."
            );
        }

        $this->assertNotEmpty(glob($root.'/js/dist/admin/components/*.js'), 'The release notes chunk should exist to be published.');
    }
}
