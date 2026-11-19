<?php

namespace ErnestDefoe\Millwright\Tests\Unit;

use ErnestDefoe\Millwright\Apply\Tree;
use ErnestDefoe\Millwright\Tests\unit\EnglishTranslator;
use ErnestDefoe\Millwright\Tests\unit\Fakes;
use ErnestDefoe\Millwright\Work\MigrationLedger;
use Flarum\Database\DatabaseMigrationRepository;
use Flarum\Database\Migrator;
use Flarum\Extension\ExtensionManager;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * Undoing an update's database changes, when one of them fails part-way.
 *
 * finish() used to cross nothing off until every down step had worked, so
 * after one failure each retry ran the already-reversed ones again, failed on
 * those, and the undo could never get past them.
 */
class MigrationLedgerTest extends TestCase
{
    private string $dir;

    private ConnectionInterface $db;

    private Migrator $migrator;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/mw-ledger-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/work/migrations/acme-widget', 0775, true);

        $this->db = Fakes::db(['migrations']);
        $this->db->getSchemaBuilder()->create('migrations', function ($table) {
            $table->string('migration');
            $table->string('extension')->nullable();
        });

        $this->migrator = new Migrator(new DatabaseMigrationRepository($this->db, 'migrations'), $this->db, new Filesystem());
    }

    protected function tearDown(): void
    {
        Tree::delete($this->dir);
    }

    public function test_a_failed_down_step_does_not_strand_the_ones_already_reversed(): void
    {
        // Ran oldest first; undone newest first: c, then b (fails), then a.
        foreach (['2026_01_01_a', '2026_01_02_b', '2026_01_03_c'] as $name) {
            $this->migration($name, $name === '2026_01_02_b');
            $this->db->table('migrations')->insert(['migration' => $name, 'extension' => 'acme-widget']);
        }

        file_put_contents($this->dir.'/work/migrations.json', json_encode(array_map(
            fn ($m) => ['migration' => $m, 'extension' => 'acme-widget'],
            ['2026_01_01_a', '2026_01_02_b', '2026_01_03_c']
        )));
        touch($this->dir.'/work/migrations.pending');

        $ledger = new MigrationLedger(EnglishTranslator::make(), $this->dir.'/work');

        try {
            $ledger->finish($this->db, $this->migrator, $this->extensions(), $this->dir.'/vendor');
            $this->fail('b was meant to fail');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('2026_01_02_b', $e->getMessage());
        }

        // c is reversed and crossed off; b and a are still owed.
        $this->assertSame(['2026_01_01_a', '2026_01_02_b'], array_column($ledger->ran(), 'migration'));
        $this->assertTrue($ledger->pending());

        // The cause is fixed; the retry picks up at b and never runs c again.
        $this->migration('2026_01_02_b', false);
        $reversed = $ledger->finish($this->db, $this->migrator, $this->extensions(), $this->dir.'/vendor');

        $this->assertCount(2, $reversed);
        $this->assertSame(['a', 'b', 'c'], $this->downsRun());
        $this->assertFalse($ledger->pending());
        $this->assertSame(0, $this->db->table('migrations')->count());
    }

    public function test_a_second_request_leaves_the_reversal_to_the_one_doing_it(): void
    {
        $this->migration('2026_01_01_a', false);
        file_put_contents($this->dir.'/work/migrations.json', json_encode([['migration' => '2026_01_01_a', 'extension' => 'acme-widget']]));
        touch($this->dir.'/work/migrations.pending');

        $held = fopen($this->dir.'/work/migrations.lock', 'c');
        $this->assertNotFalse($held);
        flock($held, LOCK_EX);

        $ledger = new MigrationLedger(EnglishTranslator::make(), $this->dir.'/work');
        $this->assertSame([], $ledger->finish($this->db, $this->migrator, $this->extensions(), $this->dir.'/vendor'));
        $this->assertSame([], $this->downsRun(), 'a second request ran a down step that another was already running');

        flock($held, LOCK_UN);
        fclose($held);
    }

    /** A migration file whose down step records itself, or throws. */
    private function migration(string $name, bool $fails): void
    {
        $short = substr($name, -1);
        $body = $fails
            ? "throw new \\RuntimeException('cannot drop $short');"
            : "file_put_contents('{$this->dir}/downs', '$short', FILE_APPEND);";

        file_put_contents(
            $this->dir.'/work/migrations/acme-widget/'.$name.'.php',
            "<?php return ['up' => function () {}, 'down' => function () { $body }];"
        );
    }

    /** @return list<string> */
    private function downsRun(): array
    {
        $ran = str_split((string) @file_get_contents($this->dir.'/downs'));
        sort($ran);

        return array_values(array_filter($ran));
    }

    private function extensions(): ExtensionManager
    {
        return (new ReflectionClass(ExtensionManager::class))->newInstanceWithoutConstructor();
    }
}
