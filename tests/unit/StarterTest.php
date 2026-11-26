<?php

namespace ErnestDefoe\Millwright\Tests\Unit;

use ErnestDefoe\Millwright\Apply\Tree;
use ErnestDefoe\Millwright\Exception\RefusedException;
use ErnestDefoe\Millwright\Run\Run;
use ErnestDefoe\Millwright\Run\RunStore;
use ErnestDefoe\Millwright\Run\Starter;
use ErnestDefoe\Millwright\Run\StepRunner;
use ErnestDefoe\Millwright\Run\StepsFactory;
use ErnestDefoe\Millwright\Tests\unit\EnglishTranslator;
use Flarum\Foundation\Paths;
use PHPUnit\Framework\TestCase;

/**
 * The admin screen and `millwright:update` start runs the same way: under one
 * lock, and never while another run is unfinished.
 */
class StarterTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/mw-starter-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/storage/millwright/locks', 0775, true);
    }

    protected function tearDown(): void
    {
        Tree::delete($this->dir);
    }

    public function test_a_run_starts_with_what_it_was_asked(): void
    {
        $run = $this->starter()->start(['acme/widget'], 'update', fn () => ['acme/widget' => '2.0.0']);

        $manifest = json_decode((string) file_get_contents($this->dir.'/storage/millwright/runs/'.$run->id.'/requested.json'), true);
        $this->assertSame(['acme/widget'], $manifest['packages']);
        $this->assertSame(['acme/widget' => '2.0.0'], $manifest['repin']);
    }

    public function test_nothing_starts_while_a_run_is_unfinished(): void
    {
        $this->store()->save(Run::start('r-old', time()));
        $asked = false;

        try {
            $this->starter()->start(['acme/widget'], 'update', function () use (&$asked) {
                $asked = true;

                return [];
            });
            $this->fail('a second run started');
        } catch (RefusedException $e) {
            $this->assertStringContainsString('already', strtolower($e->getMessage()));
        }

        $this->assertFalse($asked, 'it worked out pins before refusing');
        $this->assertSame('r-old', $this->store()->latest()?->id);
    }

    public function test_nothing_starts_while_another_start_holds_the_lock(): void
    {
        $held = fopen($this->dir.'/storage/millwright/locks/site.lock', 'c');
        flock($held, LOCK_EX);

        try {
            $this->expectException(RefusedException::class);
            $this->starter()->start(['acme/widget'], 'update', fn () => []);
        } finally {
            flock($held, LOCK_UN);
            fclose($held);
        }
    }

    /** Claudius, 2026-11-24: Stable forum, an installed beta, every partial update refused by Composer. */
    public function test_an_installed_beta_the_forum_no_longer_allows_is_asked_about_by_name(): void
    {
        file_put_contents($this->dir.'/composer.json', json_encode(['require' => ['acme/beta' => '^2.0', 'acme/widget' => '^1.0']]));
        file_put_contents($this->dir.'/composer.lock', json_encode(['packages' => [['name' => 'acme/beta', 'version' => '2.0.0-beta.1']]]));

        try {
            $this->starter()->start(['acme/widget'], 'update', fn () => []);
            $this->fail('Started without asking.');
        } catch (RefusedException $e) {
            $this->assertSame('locked_prereleases', $e->getType());
            $this->assertStringContainsString('acme/beta (beta)', $e->getMessage());
            $this->assertNull($this->store()->latest(), 'Nothing was started.');
        }

        $run = $this->starter()->start(['acme/widget'], 'update', fn () => [], true);
        $manifest = json_decode((string) file_get_contents($this->dir.'/storage/millwright/runs/'.$run->id.'/requested.json'), true);
        $this->assertSame(['acme/beta' => 'beta'], $manifest['allow']);
    }

    public function test_a_beta_dependency_is_refused_with_what_to_change_instead(): void
    {
        file_put_contents($this->dir.'/composer.json', json_encode(['require' => ['acme/ext' => '^1.0']]));
        file_put_contents($this->dir.'/composer.lock', json_encode(['packages' => [['name' => 'acme/lib', 'version' => '2.0.0-beta.1']]]));

        try {
            $this->starter()->start(['acme/ext'], 'update', fn () => [], true);
            $this->fail('A dependency cannot carry a flag.');
        } catch (RefusedException $e) {
            $this->assertSame('locked_prereleases_dependency', $e->getType());
            $this->assertStringContainsString('minimum stability to Beta', $e->getMessage());
        }
    }

    private function store(): RunStore
    {
        return new RunStore(EnglishTranslator::make(), $this->dir.'/storage/millwright/runs');
    }

    private function starter(): Starter
    {
        $runner = new StepRunner(EnglishTranslator::make(), $this->store(), $this->createStub(StepsFactory::class), fn (): int => time(), $this->dir.'/storage/millwright/locks');

        return new Starter(EnglishTranslator::make(), $this->store(), $runner, new Paths([
            'base' => $this->dir, 'public' => $this->dir.'/public', 'storage' => $this->dir.'/storage', 'vendor' => $this->dir.'/vendor',
        ]));
    }
}
