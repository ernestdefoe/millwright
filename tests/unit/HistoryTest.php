<?php

namespace ErnestDefoe\Millwright\Tests\Unit;

use ErnestDefoe\Millwright\Apply\Tree;
use ErnestDefoe\Millwright\Run\Run;
use ErnestDefoe\Millwright\Run\RunStore;
use ErnestDefoe\Millwright\Tests\unit\EnglishTranslator;
use PHPUnit\Framework\TestCase;

/** "Recently updated", as an app store keeps it: written from RunStore::save, kept past pruning. */
class HistoryTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/mw-hist-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/runs/r1', 0775, true);
        file_put_contents($this->dir.'/runs/r1/plan.json', json_encode(['changes' => [['op' => 'replace', 'package' => 'acpl/mobile-tab', 'from' => '2.0.0', 'to' => '2.0.1']]]));
        file_put_contents($this->dir.'/runs/r1/requested.json', json_encode(['packages' => ['acpl/mobile-tab'], 'mode' => 'update']));
        file_put_contents($this->dir.'/runs/r1/migrations.json', json_encode([['migration' => '2026_01_01_000000_add_variants', 'extension' => 'acpl-mobile-tab']]));
    }

    protected function tearDown(): void
    {
        Tree::delete($this->dir);
    }

    public function test_a_finished_run_is_recorded_once_and_an_undo_updates_it(): void
    {
        $store = new RunStore(EnglishTranslator::make(), $this->dir.'/runs');
        $run = Run::start('r1', 1000);

        $store->save($run);
        $this->assertSame([], $store->history(), 'a run still going is not history yet');

        $done = Run::fromArray(['state' => Run::DONE, 'movedAt' => 1100] + $run->toArray());
        $store->save($done);
        $store->save($done);   // saved again: still one entry

        $history = $store->history();
        $this->assertCount(1, $history);
        $this->assertSame('done', $history[0]['state']);
        $this->assertSame('2.0.1', $history[0]['changes'][0]['to']);
        $this->assertSame(1, $history[0]['migrations']);

        // Undo deletes the run's migrations.json and the pruner may take plan.json: the entry keeps both.
        unlink($this->dir.'/runs/r1/migrations.json');
        unlink($this->dir.'/runs/r1/plan.json');
        $store->save(Run::fromArray(['state' => Run::ROLLBACK, 'movedAt' => 1200] + $run->toArray()));

        $history = $store->history();
        $this->assertCount(1, $history);
        $this->assertSame('rolled-back', $history[0]['state']);
        $this->assertSame(1200, $history[0]['at']);
        $this->assertSame('acpl/mobile-tab', $history[0]['changes'][0]['package']);
        $this->assertSame(1, $history[0]['migrations']);
    }

    /**
     * The admin page polls the latest run every couple of seconds. It reads
     * one record, not every run the forum has kept.
     */
    public function test_the_latest_run_is_found_without_reading_every_record(): void
    {
        $store = new RunStore(EnglishTranslator::make(), $this->dir.'/runs');
        $store->save(Run::start('r-old', 100));
        $store->save(Run::start('r-new', 200));
        $store->save(Run::start('r-old', 100)->enteredPhase('fetch', 150));   // an old run saved again does not take over

        // A record that is not valid JSON would break a scan that reads them all.
        file_put_contents($this->dir.'/runs/r-broken.json', '{not json');

        $this->assertSame('r-new', $store->latest()?->id);

        // A pointer naming a record that has gone falls back to reading them.
        unlink($this->dir.'/runs/r-new.json');
        $this->assertSame('r-old', $store->latest()?->id);
    }
}
