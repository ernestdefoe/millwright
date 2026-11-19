<?php

namespace ErnestDefoe\Millwright\Tests\Unit;

use ErnestDefoe\Millwright\Console\RebuildAssetsCommand;
use ErnestDefoe\Millwright\Console\RepairFormatterCommand;
use Flarum\Formatter\Formatter;
use Flarum\Foundation\Paths;
use Flarum\Frontend\AssetManager;
use Flarum\Locale\LocaleManager;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

/**
 * Everything a class needs comes in through its constructor.
 *
 * A `resolve()` in the middle of a run couples it to the global container and
 * means none of it can be built by hand, which is how the phase classes are
 * tested. The one exception is a queued job's failed(): Laravel calls it with
 * the exception alone, and the job's constructor runs at dispatch time, so
 * anything injected there would be serialized into the queue payload.
 */
class InjectionTest extends TestCase
{
    private const ALLOWED = ['src/Run/StepJob.php'];

    public function test_nothing_asks_the_container_for_a_service(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/src', \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            $relative = substr($file->getPathname(), strlen($root) + 1);

            if ($file->getExtension() !== 'php' || in_array($relative, self::ALLOWED, true)) {
                continue;
            }

            foreach (file($file->getPathname()) ?: [] as $n => $line) {
                // The global helper only: not ->resolve(), ::resolve() or a method named resolve.
                if ((preg_match('/(?<![\w>:$])resolve\(/', $line) && ! preg_match('/function\s+resolve\(/', $line)) || str_contains($line, 'Container::getInstance(')) {
                    $offenders[] = $relative.':'.($n + 1).': '.trim($line);
                }
            }
        }

        $this->assertSame([], $offenders, "Inject these through the constructor:\n".implode("\n", $offenders));
    }

    public function test_the_console_commands_are_built_by_the_container(): void
    {
        $container = new Container();
        $container->instance(Paths::class, new Paths(['base' => '/x', 'public' => '/x/public', 'storage' => '/x/storage', 'vendor' => '/x/vendor']));
        $container->instance(Formatter::class, $this->createStub(Formatter::class));
        $container->instance(SettingsRepositoryInterface::class, $this->createStub(SettingsRepositoryInterface::class));
        $container->instance(LocaleManager::class, $this->createStub(LocaleManager::class));
        $container->instance(AssetManager::class, $this->createStub(AssetManager::class));

        $this->assertSame('millwright:repair-formatter', $container->make(RepairFormatterCommand::class)->getName());
        $this->assertSame('millwright:rebuild-assets', $container->make(RebuildAssetsCommand::class)->getName());
    }

    /**
     * ComposerSteps grew to 1,390 lines covering four phases. Split into
     * Planner, SiteCheck and Finaliser; this keeps any one file from quietly
     * growing back.
     */
    public function test_no_source_file_outgrows_600_lines(): void
    {
        $root = dirname(__DIR__, 2);
        $long = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/src', \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php' && ($lines = count(file($file->getPathname()) ?: [])) > 600) {
                $long[] = substr($file->getPathname(), strlen($root) + 1).' ('.$lines.' lines)';
            }
        }

        $this->assertSame([], $long);
    }
}
