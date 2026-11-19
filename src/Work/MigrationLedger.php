<?php

namespace ErnestDefoe\Millwright\Work;

use ErnestDefoe\Millwright\Exception\RefusedException;
use ErnestDefoe\Millwright\Host\StateFile;
use Flarum\Database\Migrator;
use Flarum\Extension\ExtensionManager;
use Flarum\Locale\TranslatorInterface;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Which database migrations an update ran, so undoing it can reverse them.
 *
 * Undo used to put the old CODE back over the new SCHEMA. Moving files is
 * all a rename can do, and a migration the update ran stays run: the older
 * version then boots against columns and tables it has never heard of, or
 * misses ones it needs. ClaudiusH asked the obvious question on 2026-10-07 —
 * "this should only be offered if the update did not include a migration,
 * right?" — and the honest answer was that the confirm box merely warned.
 *
 * So the update writes down what it ran, and undo reverses exactly those,
 * newest first, through each migration's own `down`. If any of them has no
 * down step, undo refuses before touching anything: old code on a newer
 * database is the outcome this exists to prevent.
 *
 * In TWO requests, and from COPIES. Found undoing Flarum rc.8 → nightly,
 * 2026-10-07: nightly's API middleware reads the `asset_revisions` table its
 * own migration creates, on every response. Reversing that migration inside
 * the undo request — which still has nightly's code loaded — dropped the
 * table out from under the response, and the undo answered 500 halfway. So
 * the first request only checks, puts the files back and restores the
 * version Flarum records; the reversal runs in the NEXT request, booted from
 * the restored code. That code no longer contains the new migration files,
 * which is why the update keeps a copy of each one it ran.
 *
 * Recorded in two halves so a run killed mid-step still knows: the "before"
 * state is saved once, before migrate runs, and the difference is taken after.
 */
class MigrationLedger
{
    public function __construct(
        protected TranslatorInterface $translator,
        protected string $workDir,
    ) {
    }

    /** Before `flarum migrate`: what has already run, and the version Flarum records. Saved once. */
    public function before(Forum $forum): void
    {
        $path = $this->workDir.'/migrations.before.json';

        if (! is_file($path)) {
            // Without it, every migration the forum has ever run would look
            // new afterwards, and undo would try to reverse them all.
            StateFile::writeOrFail($path, [
                'migrations' => $this->current($forum->db),
                'version' => $forum->recordedVersion(),
            ], $this->translator);
        }
    }

    /**
     * After it: what this update added, in the order it ran, with a copy of
     * each migration file so undo can still reach its down step later.
     */
    public function after(ConnectionInterface $db, ExtensionManager $extensions, string $vendorPath, LoggerInterface $log): array
    {
        if (! is_file($this->workDir.'/migrations.before.json')) {
            throw new RuntimeException($this->translator->trans('ernestdefoe-millwright.api.fs.read_failed', ['path' => $this->workDir.'/migrations.before.json']));
        }

        $before = $this->beforeState();
        $seen = array_flip(array_map(fn ($m) => $m['extension'].'|'.$m['migration'], $before['migrations']));
        $added = array_values(array_filter(
            $this->current($db),
            fn ($m) => ! isset($seen[$m['extension'].'|'.$m['migration']])
        ));
        // Flarum runs them in filename order, which starts with a timestamp.
        usort($added, fn ($a, $b) => strcmp($a['migration'], $b['migration']));

        foreach ($added as $m) {
            $from = $this->liveDir($m['extension'], $extensions, $vendorPath).'/'.$m['migration'].'.php';
            $to = $this->copyDir($m['extension']).'/'.$m['migration'].'.php';
            @mkdir(dirname($to), 0775, true);

            /*
             * Not fatal: the migration has already run, and failing the update
             * here would only offer an undo that then could not find it. Without
             * the copy, undo falls back to the live file, and refuses (changing
             * nothing) if that has gone. Logged so the reason is findable.
             */
            if (! @copy($from, $to)) {
                $log->warning("[millwright] could not keep a copy of migration {$m['migration']} for undo (from $from)");
            }
        }

        $this->record($added);

        return $added;
    }

    /**
     * Written beside and renamed into place, so a kill mid-write never leaves
     * a ledger that lists half a migration.
     *
     * @param list<array{migration:string, extension:?string}> $migrations
     */
    protected function record(array $migrations): void
    {
        $path = $this->workDir.'/migrations.json';

        if (! StateFile::write($path, $migrations)) {
            throw new RuntimeException($this->translator->trans('ernestdefoe-millwright.api.migrations.ledger_write_failed', ['path' => $path]));
        }
    }

    /** @return list<array{migration:string, extension:?string}> what this update ran */
    public function ran(): array
    {
        return array_values(StateFile::read($this->workDir.'/migrations.json'));
    }

    /**
     * First request: refuse, changing nothing, unless every migration this
     * update ran can be reversed. Then put back the version Flarum records —
     * without it the restored code finds a database claiming to be newer and
     * shows "Update Flarum" (503) on every page — and leave the reversal
     * itself for the next request.
     *
     * @return bool whether a reversal is now pending
     */
    public function prepare(Forum $forum, string $vendorPath): bool
    {
        if ($this->ran() === []) {
            $this->restoreVersion($forum);

            return false;
        }

        $this->downs($forum->migrator, $forum->extensions, $vendorPath);   // throws if any cannot be reversed
        $this->restoreVersion($forum);
        touch($this->workDir.'/migrations.pending');

        return true;
    }

    public function pending(): bool
    {
        return is_file($this->workDir.'/migrations.pending');
    }

    /**
     * Second request, booted from the restored code: reverse what the update
     * ran, newest first.
     *
     * @return list<string> one line per migration reversed
     */
    public function finish(ConnectionInterface $db, Migrator $migrator, ExtensionManager $extensions, string $vendorPath): array
    {
        if (! $this->pending()) {
            return [];
        }

        /*
         * The admin page asks for its state every couple of seconds, and each
         * of those requests may arrive here. One reverses; the others leave it
         * to that one rather than running the same down steps twice.
         */
        $lock = @fopen($this->workDir.'/migrations.lock', 'c');

        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            return [];
        }

        try {
            return $this->reverse($db, $migrator, $extensions, $vendorPath);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return list<string> */
    protected function reverse(ConnectionInterface $db, Migrator $migrator, ExtensionManager $extensions, string $vendorPath): array
    {
        if (! $this->pending()) {
            return [];   // finished by the request that held the lock before this one
        }

        $done = [];
        $left = $this->ran();

        foreach ($this->downs($migrator, $extensions, $vendorPath) as [$m, $down]) {
            try {
                call_user_func($down, $db->getSchemaBuilder());
            } catch (Throwable $e) {
                throw new RuntimeException(
                    $this->translator->trans('ernestdefoe-millwright.api.migrations.undo_stopped', ['migration' => $m['migration'], 'error' => $e->getMessage()]).' '
                    .($done ? $this->translator->trans('ernestdefoe-millwright.api.migrations.already_reversed', ['list' => implode(', ', $done)]) : $this->translator->trans('ernestdefoe-millwright.api.migrations.none_reversed'))
                );
            }

            $migrator->getRepository()->delete($m['migration'], $m['extension']);
            $done[] = $m['migration'];

            /*
             * Crossed off as it goes. A later down step that fails must not
             * leave this one on the list: the retry would run it a second time
             * against a schema it already took apart, fail on that, and never
             * get past it.
             */
            $left = array_values(array_filter($left, fn ($r) => $r !== $m));
            $this->record($left);
        }

        // Reversed once; a second undo of the same run has nothing left to do.
        @unlink($this->workDir.'/migrations.json');
        @unlink($this->workDir.'/migrations.pending');

        return array_map(fn ($name) => $this->translator->trans('ernestdefoe-millwright.api.migrations.reversed', ['migration' => $name]), $done);
    }

    /**
     * Each migration's down step, newest first, or a refusal naming the one
     * that has none.
     *
     * @return list<array{0: array{migration:string, extension:?string}, 1: callable}>
     */
    protected function downs(Migrator $migrator, ExtensionManager $extensions, string $vendorPath): array
    {
        $steps = [];

        foreach (array_reverse($this->ran()) as $m) {
            $copy = $this->copyDir($m['extension']);
            $dir = is_file($copy.'/'.$m['migration'].'.php') ? $copy : $this->liveDir($m['extension'], $extensions, $vendorPath);
            $migration = $migrator->resolve($dir, $m['migration']);

            if (! isset($migration['down']) || ! is_callable($migration['down'])) {
                throw new RefusedException(
                    $m['extension']
                        ? $this->translator->trans('ernestdefoe-millwright.api.migrations.irreversible_ext', ['migration' => $m['migration'], 'extension' => $m['extension']])
                        : $this->translator->trans('ernestdefoe-millwright.api.migrations.irreversible', ['migration' => $m['migration']]),
                    'irreversible_migration',
                    409
                );
            }

            $steps[] = [$m, $migration['down']];
        }

        return $steps;
    }

    protected function restoreVersion(Forum $forum): void
    {
        $version = $this->beforeState()['version'];

        if (is_string($version) && $version !== '') {
            $forum->settings->set('version', $version);
        }
    }

    /** @return array{migrations: list<array{migration:string, extension:?string}>, version: ?string} */
    protected function beforeState(): array
    {
        $raw = StateFile::read($this->workDir.'/migrations.before.json');

        // A run recorded before the version was kept stored the bare list.
        return array_is_list($raw)
            ? ['migrations' => $raw, 'version' => null]
            : ['migrations' => (array) ($raw['migrations'] ?? []), 'version' => $raw['version'] ?? null];
    }

    protected function liveDir(?string $extension, ExtensionManager $extensions, string $vendorPath): string
    {
        return $extension === null
            ? $vendorPath.'/flarum/core/migrations'
            : $extensions->getExtension($extension)?->getPath().'/migrations';
    }

    protected function copyDir(?string $extension): string
    {
        return $this->workDir.'/migrations/'.($extension ?? 'core');
    }

    /** @return list<array{migration:string, extension:?string}> */
    protected function current(ConnectionInterface $db): array
    {
        return $db->table('migrations')->get(['migration', 'extension'])
            ->map(fn ($row) => ['migration' => (string) $row->migration, 'extension' => $row->extension !== null ? (string) $row->extension : null])
            ->values()->all();
    }
}
