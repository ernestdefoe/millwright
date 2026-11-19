<?php

namespace ErnestDefoe\Millwright\Work;

use ErnestDefoe\Millwright\Apply\Tree;
use ErnestDefoe\Millwright\Host\CoreVersion;
use ErnestDefoe\Millwright\Host\FlarumUpdater;
use ErnestDefoe\Millwright\Host\Opcache;
use ErnestDefoe\Millwright\Host\StorageDir;
use ErnestDefoe\Millwright\Prune\Pruner;
use ErnestDefoe\Millwright\Prune\Retention;
use ErnestDefoe\Millwright\Run\Reverted;
use ErnestDefoe\Millwright\Run\Run;
use GuzzleHttp\ClientInterface;
use RuntimeException;
use Throwable;

/**
 * The finalise phase: make Composer's record, the database, the assets and
 * the caches agree with the files the apply phase swapped in. Also the one
 * piece of it that cannot wait for this phase: bringing the database up to
 * date in the request that swapped Flarum itself.
 *
 * Every item is idempotent by nature, which is what lets a run killed between
 * the work and the saved progress safely do it again.
 */
class Finaliser
{
    public function __construct(
        protected RunContext $run,
        protected Planner $planner,
        protected SiteCheck $site,
        protected ClientInterface $http,
    ) {
    }

    public function finalise(string $item, Run $run): string
    {
        return match ($item) {
            'register' => $this->register(),
            'migrations' => $this->migrate(),
            'assets' => $this->flarum('assets:publish', $this->run->translator->trans('ernestdefoe-millwright.api.composer.assets_published')),
            'caches' => $this->clearCaches(),
            'check the site again' => $this->site->verify($run),
            'tidy the trash' => $this->tidyTrash(),
            /*
             * Last, and it is the step that decides whether any of the others
             * were visible. On a host with opcache.validate_timestamps off, every
             * phase above can succeed and the site carries on serving the old
             * compiled code until PHP is restarted: an update that reports
             * success and changed nothing anybody can see.
             */
            'code cache' => $this->site->codeCache(),
            default => $this->run->translator->trans('ernestdefoe-millwright.api.composer.nothing_to_do'),
        };
    }

    /**
     * Bring the database up to date in the SAME request that swapped core.
     *
     * Once Flarum's files are newer than its database, every request gets
     * the "Update Flarum" page (Millwright's own step endpoint included), so
     * the finalise phase could never be reached from the web, and neither
     * could the undo button. Where a process can be started, `php flarum
     * migrate` runs fresh, on the new code; where it cannot, Flarum's own
     * updater is asked to (see FlarumUpdater). If neither works, this request
     * undoes the update before it ends, because no later one could.
     */
    public function bridgeCore(): string
    {
        $run = $this->run;
        (new MigrationLedger($run->translator, $run->workDir))->before($run->forum);
        $was = $run->forum->recordedVersion();

        /*
         * Only a new VERSION puts the forum behind "Update Flarum". A core
         * that moves without one (the nightly build, 2.x-dev, to the release
         * it becomes, both of which say 2.0.0) leaves the site answering, so
         * the migrations step later in this run is still reachable and does
         * the work. Bridging here would undo a correct update: migrate cannot
         * change a version that was already right, and on a host without
         * proc_open Flarum's updater is not there to ask.
         */
        if ($was !== null && $was === CoreVersion::onDisk($run->vendor())) {
            return $run->translator->trans('ernestdefoe-millwright.api.composer.core_same_version', ['version' => $was]);
        }

        try {
            $now = $this->migrateCore();

            if ($now === $was) {
                throw new RuntimeException($run->translator->trans('ernestdefoe-millwright.api.composer.core_db_unchanged', ['version' => $was]));
            }

            return $run->translator->trans('ernestdefoe-millwright.api.composer.core_moved', ['from' => $was, 'to' => $now]);
        } catch (Throwable $e) {
            $restore = $run->restore();

            if ($restore === null || ! $restore->possible()) {
                throw new RuntimeException($run->translator->trans('ernestdefoe-millwright.api.composer.core_db_failed', ['error' => $e->getMessage()]));
            }

            $done = $restore->run();

            throw new Reverted(
                $run->translator->trans('ernestdefoe-millwright.api.composer.core_db_failed_undone', ['error' => $e->getMessage()]).($done['note'] !== null ? ' '.$done['note'] : ''),
                $done['undone']
            );
        }
    }

    /** Migrate on the new core's code, and return the version Flarum now records. */
    protected function migrateCore(): ?string
    {
        $run = $this->run;
        (new Opcache($run->translator))->clear();

        if ($run->composer->canSpawn()) {
            $this->registerWith(null);
            $this->flarum('migrate', '');
        } else {
            (new FlarumUpdater($run->translator, $this->http, $run->siteUrl, $run->storagePath))->migrate();
        }

        $now = $run->forum->recordedVersion();

        // The database is now ahead of the web server's compiled code: close
        // that window at once rather than within opcache's own timer.
        $this->site->clearWebCache();

        return $now;
    }

    /**
     * Remove rollback copies no rollback can reach any more.
     *
     * Never fails the run, and never takes long. The update is finished and
     * verified by now; housekeeping that throws must not turn it red and offer
     * to undo correct work, so a failure is reported in the log and left for
     * the nightly prune. The time budget keeps a host that cuts requests at 30
     * seconds from killing this step mid-way: what is left is picked up later.
     */
    protected function tidyTrash(): string
    {
        $translator = $this->run->translator;

        if ($this->run->storagePath === '') {
            return $translator->trans('ernestdefoe-millwright.api.composer.tidy_no_storage');
        }

        try {
            $dir = StorageDir::of($this->run->storagePath);
            $summary = (new Pruner($translator, $dir, new Retention($dir)))->prune('finished update', 10.0);
        } catch (Throwable $e) {
            return $translator->trans('ernestdefoe-millwright.api.composer.tidy_left', ['error' => $e->getMessage()]);
        }

        if ($summary['removed'] === 0) {
            return $translator->trans('ernestdefoe-millwright.api.composer.tidy_nothing');
        }

        return $translator->trans('ernestdefoe-millwright.api.composer.tidy_removed', ['count' => $summary['removed'], 'size' => Pruner::human((int) $summary['freed'])])
            .($summary['complete'] ? '' : ' '.$translator->trans('ernestdefoe-millwright.api.composer.tidy_rest_tonight'));
    }

    /**
     * Clear Flarum's caches, and leave the formatter able to render.
     *
     * `cache:clear` on its own takes post rendering down, and this is not a
     * race: it happens every time on any forum whose cache driver is not the
     * file store, which is every forum running fof/redis.
     *
     * The formatter is two halves that must agree: a serialized renderer cached
     * under `flarum.formatter`, and the generated
     * storage/formatter/Renderer_<hash>.php it is an instance of. cache:clear
     * unlinks that file and flushes the APPLICATION cache, but core gives the
     * formatter its own FileStore, so the entry survives in a different store,
     * names a class whose file is gone, and every post render dies with
     * `__PHP_Incomplete_Class`. `rememberForever` means nothing fixes it later.
     *
     * An earlier version of this method deleted storage/formatter/*.php
     * itself, on the theory that clearing both halves left a consistent state.
     * That was precisely backwards: deleting the file while the entry survives
     * IS the break, so it manufactured the failure it was written to prevent,
     * twice per run. Two live sites went down that way.
     *
     * The repair command forgets the entry through the formatter's OWN cache and
     * rebuilds both halves together.
     */
    protected function clearCaches(): string
    {
        $run = $this->run;

        // A cache left root-owned (a `php flarum cache:clear` run as root while
        // this run waited) would fail the clear below; see StaleCache.
        $setAside = StaleCache::describe(StaleCache::setAside($run->storagePath), $run->translator);

        $this->flarum('cache:clear', 'cleared');
        $this->flarum('millwright:repair-formatter', 'formatter rebuilt');
        // Before Flarum 2.0.0, cache:clear leaves the compiled bundles as they
        // were: the update's new screen never reached the browser.
        $run->needsFreshCode();
        $rebuilt = (new FlarumCommand($run->translator, $run->installPath, $run->composer, $run->forum->container))->run('millwright:rebuild-assets');
        $warnings = array_values(array_filter(
            array_map('trim', explode("\n", (string) $rebuilt['output'])),
            fn (string $line) => str_starts_with($line, 'Warning:')
        ));

        return $run->translator->trans('ernestdefoe-millwright.api.composer.caches_cleared')
            .($setAside === '' ? '' : '. '.$setAside)
            .($warnings === [] ? '' : '. '.implode(' ', $warnings));
    }

    /**
     * Make Composer's record and autoloader match the tree, without letting it
     * re-extract anything. See InstalledRecord for the outage this prevents.
     *
     * `install`, not `dump-autoload`, and the difference is whether the
     * update exists at all. Composer's record of what is installed is
     * vendor/composer/installed.json, not composer.lock, and `dump-autoload`
     * regenerates the autoloader FROM that record. So a package this pipeline
     * placed by hand was autoloaded by nothing and invisible to Flarum: the
     * files were right, the lock was right, every phase reported success, and
     * the extension simply did not appear. Found by installing one on a real
     * forum and looking for it afterwards. `install` reconciles the record with
     * the lock, and is cheap because the tree already agrees: measured at 2.2s
     * for a one-package change on a 226-package forum.
     *
     * The dry run is the guard, not a formality. If Composer would still
     * change files after the record is synced, something disagrees that this
     * step does not understand, and finding that out by letting Composer
     * delete its own dependencies is how a site goes down. Stopping here leaves
     * the applied tree in place and rollback available.
     */
    protected function register(): string
    {
        $this->run->needsFreshCode();

        $this->planner->raisePins();

        $snapshot = $this->run->composer->inProcess() ? $this->snapshotAutoloader() : null;

        try {
            $done = $this->registerWith($snapshot);
            $this->site->dropStaleCode();

            return $done;
        } catch (Throwable $e) {
            if ($snapshot !== null) {
                $this->restoreAutoloader($snapshot);
            }

            throw $e;
        }
    }

    protected function registerWith(?string $snapshot): string
    {
        $run = $this->run;
        (new InstalledRecord($run->translator, $run->installPath))->syncFromLock();

        $dry = $run->composer->run(['install', '--no-scripts', '--dry-run']);
        if ($dry['code'] !== 0) {
            throw new RuntimeException($run->translator->trans('ernestdefoe-millwright.api.composer.composer_failed')."\n".$dry['output']);
        }

        $planned = InstalledRecord::plannedOperations($dry['output']);
        if ($planned > 0) {
            throw new RuntimeException(
                $run->translator->trans('ernestdefoe-millwright.api.composer.still_wants_changes', ['count' => $planned])."\n"
                .$dry['output']
            );
        }

        $registered = $run->translator->trans('ernestdefoe-millwright.api.composer.registered');

        if ($snapshot === null) {
            return $this->composerCommand(['install', '--no-scripts'], $registered);
        }

        /*
         * In-process, the autoloader is written by THIS request, and a host
         * that kills it mid-write would leave vendor/composer half old, half
         * new: every page a fatal. The snapshot taken before the record was
         * synced goes back on the way down, so a kill leaves the autoloader
         * exactly as the apply phase left it, which rollback understands.
         */
        $done = false;
        register_shutdown_function(function () use (&$done, $snapshot): void {
            // @phpstan-ignore booleanNot.alwaysTrue ($done is set by reference once the work completes)
            if (! $done) {
                $this->restoreAutoloader($snapshot);
            }
        });

        try {
            return $this->composerCommand(['install', '--no-scripts'], $registered);
        } finally {
            $done = true;
        }
    }

    /**
     * Copy Composer's record and autoloader aside before an in-process install.
     */
    protected function snapshotAutoloader(): string
    {
        $dir = $this->run->workDir.'/register.before';
        $source = $this->run->installPath.'/vendor/composer';

        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException($this->run->translator->trans('ernestdefoe-millwright.api.composer.autoloader_copy_failed'));
        }

        foreach ([...(glob($source.'/*.php') ?: []), $source.'/installed.json', $this->run->installPath.'/vendor/autoload.php'] as $file) {
            if (is_file($file) && ! copy($file, $dir.'/'.$this->snapshotName($file))) {
                throw new RuntimeException($this->run->translator->trans('ernestdefoe-millwright.api.composer.autoloader_copy_failed'));
            }
        }

        return $dir;
    }

    protected function restoreAutoloader(string $dir): void
    {
        foreach (glob($dir.'/*') ?: [] as $saved) {
            $name = basename($saved);
            $target = $name === 'autoload.php'
                ? $this->run->installPath.'/vendor/autoload.php'
                : $this->run->installPath.'/vendor/composer/'.substr($name, strlen('composer.'));

            Tree::replaceFile($saved, $target);
        }
    }

    protected function snapshotName(string $file): string
    {
        return basename(dirname($file)) === 'composer' ? 'composer.'.basename($file) : basename($file);
    }

    /** @param list<string> $args */
    protected function composerCommand(array $args, string $note): string
    {
        $result = $this->run->composer->run($args);

        if ($result['code'] !== 0) {
            throw new RuntimeException($this->run->translator->trans('ernestdefoe-millwright.api.composer.composer_failed')."\n".$result['output']);
        }

        return $note;
    }

    /** `flarum migrate`, writing down what it ran so undo can reverse it. See MigrationLedger. */
    protected function migrate(): string
    {
        $run = $this->run;
        $ledger = new MigrationLedger($run->translator, $run->workDir);

        $ledger->before($run->forum);
        $this->flarum('migrate', 'migrations run');
        $added = $ledger->after($run->forum->db, $run->forum->extensions, $run->vendorPath, $run->forum->log);

        return $added === []
            ? $run->translator->trans('ernestdefoe-millwright.api.composer.migrations_none')
            : $run->translator->trans('ernestdefoe-millwright.api.composer.migrations_run', ['count' => count($added)]);
    }

    protected function flarum(string $command, string $note): string
    {
        /*
         * After the swap, and in a process that has the new code: its own
         * process where the host allows one; otherwise this request, which
         * needsFreshCode() has made sure started after the swap and after
         * opcache let go of the old files. Booting Flarum's commands inside the
         * request that replaced its files would load a half-old, half-new class
         * map.
         *
         * Through the same PhpBinary the Composer phases use. When these
         * disagreed, an update could get through planning, downloading and the
         * swap (every expensive, risky part) and then fail on the last phase
         * because this one alone was spawned with a binary that cannot run a
         * script. Under FPM, PHP_BINARY is php-fpm.
         */
        $this->run->needsFreshCode();

        $result = (new FlarumCommand($this->run->translator, $this->run->installPath, $this->run->composer, $this->run->forum->container))->run($command);

        if ($result['code'] !== 0) {
            throw new RuntimeException($this->run->translator->trans('ernestdefoe-millwright.api.composer.flarum_command_failed', ['command' => $command])."\n".FlarumCommand::excerpt($result['output']));
        }

        return $note;
    }
}
