<?php

namespace ErnestDefoe\Millwright\Work;

use ErnestDefoe\Millwright\Config\JsonFile;
use ErnestDefoe\Millwright\Plan\Change;
use ErnestDefoe\Millwright\Plan\LockDiff;
use ErnestDefoe\Millwright\Run\NotYet;
use RuntimeException;

/**
 * The plan phase: ask Composer what would change, write it down, and refuse
 * before anything moves if the run would hit a file it cannot change.
 */
class Planner
{
    /**
     * How many times a resolve killed by the host is tried before giving up.
     *
     * Six, from a measurement rather than a feeling: on dev's real
     * composer.json (346 packages, 22 protected GitHub repositories) a cold
     * in-process resolve took 144s and a warm one 27s. Each killed attempt
     * keeps what it downloaded, so a host that cuts requests at 30 seconds gets
     * there in about five tries; three would give up on it with the cache
     * nearly full.
     */
    protected const RESOLVE_ATTEMPTS = 6;

    public function __construct(protected RunContext $run)
    {
    }

    /**
     * Ask Composer what would change, and write it down.
     *
     * `--no-install`: this updates composer.lock and NOTHING else. Composer's
     * own install would rebuild a tree; all that is wanted here is the answer to
     * "what moves", which is 16 seconds and 165 MB rather than minutes and a
     * mutated vendor directory. The install is ours to do, one package at a
     * time, after the user has seen the list.
     */
    public function resolve(): string
    {
        $run = $this->run;

        /*
         * Saved BEFORE Composer is allowed to rewrite them, and used by the
         * rollback. `--no-install` updates the lock the moment it succeeds, so
         * without a copy taken here there is no way back to the site's own
         * record of itself, and a rollback that restores the files but not the
         * lock leaves a site whose manifest describes work that was undone.
         *
         * Taken ONCE, and a retry starts from it. A resolve killed part-way
         * (a host's time limit, a proxy timeout) can leave composer.json
         * edited by `require` and the lock half-way to new. Copying again on
         * the retry would save THAT as "before", and the rollback would put
         * back the broken state; resolving on top of it would plan from it.
         */
        $this->startFromSaved();
        $before = RunContext::readJson($run->workDir.'/composer.lock.before');

        $inProcess = $run->composer->inProcess();

        if ($inProcess) {
            $needsGit = new NeedsGit($run->translator, $run->installPath, $run->composer->composerHome());
            $blockers = $needsGit->blockers();

            if ($blockers !== []) {
                throw new RuntimeException($needsGit->explain($blockers));
            }
        }

        $raised = $this->raisePins();

        $result = $inProcess ? $this->resolveInProcess($this->arguments()) : $run->composer->run($this->arguments());
        $after = RunContext::readJson($run->installPath.'/composer.lock');

        if ($result['code'] !== 0 && ! $this->didWhatWasAsked($before, $after)) {
            // Composer's own words, not a summary of them: it is usually precise
            // about which constraint could not be satisfied, and paraphrasing
            // that loses the only part anyone can act on.
            throw new RuntimeException($run->translator->trans('ernestdefoe-millwright.api.composer.resolve_failed')."\n".$result['output']);
        }

        $note = $this->writePlan($before, $after);

        return $raised === [] ? $note : implode('; ', $raised).'. '.$note;
    }

    /**
     * `require` for an install, `update` for an update, and they are not
     * interchangeable: running `update` on a package that is not installed
     * does nothing whatsoever and exits 0. The run would sail through every
     * phase, change nothing, and report success: the exact silent failure
     * this whole extension exists to stop.
     *
     * `require --no-install` writes composer.json as well as the lock, and
     * reverts its own edit if the resolve fails. composer.json.before is
     * saved first so a rollback after a SUCCESSFUL resolve can undo it too.
     *
     * @return list<string>
     */
    protected function arguments(): array
    {
        $requested = $this->run->requested;

        return match ($this->run->mode) {
            'install' => array_merge(['require'], $requested, ['--no-install', '--no-scripts']),
            /*
             * `remove` takes the package out of composer.json AND re-resolves
             * the lock, which is what makes the dependencies it dragged in go
             * too. `--no-install` stops Composer touching vendor: the removal
             * happens through the same journalled apply as everything else, so
             * the files go to the trash rather than being deleted and an
             * uninstall is as reversible as an update.
             *
             * `--no-update-with-dependencies`: a removal only takes things
             * away. Composer's default lets the removed package's dependencies
             * update too, so taking one extension out could move a library it
             * shared with another, which is a download and a change nobody
             * asked for (Ernest + ClaudiusH, 2026-10-13).
             */
            'remove' => array_merge(['remove'], $requested, ['--no-install', '--no-scripts', '--no-update-with-dependencies']),
            /*
             * `-w`, NOT `-W`.
             *
             * `--with-all-dependencies` also updates packages that are ROOT
             * requirements, so asking to update one extension moved twenty-five
             * unrelated packages on a live forum: the whole illuminate stack,
             * commonmark, monolog. Nobody chose that, the extension being
             * updated did not need it, and on a site that had just been
             * carefully pinned it was precisely the thing the pinning existed
             * to prevent.
             *
             * `--with-dependencies` still lets a package's own dependencies move
             * when the new version needs them, which is the case that made -W
             * look necessary. What it will not do is quietly re-resolve things
             * the admin has deliberately fixed. If an update genuinely requires
             * a root requirement to move, Composer now says so and the admin
             * decides, which is the whole posture of this extension.
             */
            default => array_merge(['update'], $requested, ['--with-dependencies', '--no-install']),
        };
    }

    /**
     * The new lock is put back immediately. Composer has already written it,
     * and leaving it in place while the packages on disk are still the old ones
     * would mean a lock that disagrees with vendor/, which the next resolve
     * would silently build on. The plan written here is the record of what has
     * to happen to make them agree again.
     *
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     */
    protected function writePlan(array $before, array $after): string
    {
        $run = $this->run;
        $diff = new LockDiff();
        $changes = $diff->between($before, $after);

        file_put_contents($run->workDir.'/plan.json', json_encode([
            'changes' => array_map(fn (Change $c) => $c->toArray(), $changes),
            'sources' => $diff->sources($after),
            'reasons' => $diff->reasons($changes, $after, $run->requested),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if ($changes === []) {
            return match ($run->mode) {
                'install' => $run->translator->trans('ernestdefoe-millwright.api.composer.nothing_changed_install'),
                'remove' => $run->translator->trans('ernestdefoe-millwright.api.composer.nothing_changed_remove'),
                default => $this->whyNothingMoved(),
            };
        }

        $shown = array_map(fn (Change $c) => $c->describe($run->translator), array_slice($changes, 0, 5));

        return $run->translator->trans('ernestdefoe-millwright.api.composer.will_change', ['count' => count($changes), 'list' => implode(', ', $shown)])
            .(count($changes) > 5 ? $run->translator->trans('ernestdefoe-millwright.api.composer.and_more', ['count' => count($changes) - 5]) : '');
    }

    /**
     * Copy the manifests aside the first time; put them back on every retry.
     */
    protected function startFromSaved(): void
    {
        foreach (['composer.lock', 'composer.json'] as $file) {
            $saved = $this->run->workDir.'/'.$file.'.before';
            $live = $this->run->installPath.'/'.$file;

            if (is_file($saved)) {
                if (@file_get_contents($saved) !== @file_get_contents($live)) {
                    RunContext::replaceFile($saved, $live);
                }
            } elseif (! copy($live, $saved)) {
                throw new RuntimeException($this->run->translator->trans('ernestdefoe-millwright.api.composer.save_before_resolve_failed', ['file' => $file]));
            }
        }
    }

    /**
     * The resolve, inside this request, and what happens when the host kills
     * the request before Composer finishes.
     *
     * On shared hosting the request has a time limit Millwright may not be
     * allowed to lift, and a proxy in front of it may cut it off regardless. A
     * kill there is not a failure of the update, so it must not look like one:
     *
     *   - A shutdown function puts composer.json and the lock back the moment
     *     the request dies, so nothing is ever left half-written: PHP runs it
     *     after a "Maximum execution time" or memory fatal. A kill PHP never
     *     hears about (FPM's request_terminate_timeout, SIGKILL) is caught on
     *     the retry instead, by startFromSaved().
     *   - Composer keeps every package list it downloaded in COMPOSER_HOME
     *     under storage/, so the next attempt starts warm and gets further.
     *   - The admin page simply polls again; the next poll sees the attempt
     *     that never came back, says so in the run's log, and tries again, up
     *     to RESOLVE_ATTEMPTS times, then stops with a sentence naming the limit.
     *
     * @param list<string> $args
     * @return array{code:int, output:string}
     */
    protected function resolveInProcess(array $args): array
    {
        $path = $this->run->workDir.'/resolve.attempt.json';
        $prev = is_file($path) ? RunContext::readJson($path) : [];
        $attempts = (int) ($prev['attempts'] ?? 0);

        if ($attempts > 0) {
            $this->retryOrGiveUp($path, $prev, $attempts);
        }

        $started = microtime(true);
        @file_put_contents($path, json_encode(['attempts' => $attempts + 1, 'announced' => false, 'startedAt' => $started]));

        $done = false;
        $workDir = $this->run->workDir;
        $installPath = $this->run->installPath;

        register_shutdown_function(static function () use (&$done, $path, $started, $workDir, $installPath): void {
            // @phpstan-ignore if.alwaysFalse ($done is set by reference once the work completes)
            if ($done) {
                return;
            }

            foreach (['composer.lock', 'composer.json'] as $file) {
                if (is_file("$workDir/$file.before")) {
                    RunContext::replaceFile("$workDir/$file.before", "$installPath/$file");
                }
            }

            $error = error_get_last();
            $message = (string) ($error['message'] ?? '');
            $record = (array) json_decode((string) @file_get_contents($path), true);
            $record['stopped'] = match (true) {
                str_contains($message, 'Maximum execution time') => 'time',
                str_contains($message, 'Allowed memory size') => 'memory',
                default => 'unknown',
            };
            $record['seconds'] = (int) round(microtime(true) - $started);
            $record['timeLimit'] = (int) ini_get('max_execution_time');
            $record['memoryLimit'] = (string) ini_get('memory_limit');
            @file_put_contents($path, json_encode($record));
        });

        try {
            return $this->run->composer->run($args);
        } finally {
            $done = true;
            @unlink($path);
        }
    }

    /**
     * An earlier attempt never came back. Say so once in the run's log, then
     * try again; after RESOLVE_ATTEMPTS, stop and name the limit.
     *
     * @param array<string,mixed> $prev
     */
    protected function retryOrGiveUp(string $path, array $prev, int $attempts): void
    {
        $translator = $this->run->translator;
        $how = $this->howItStopped($prev);

        if ($attempts >= self::RESOLVE_ATTEMPTS) {
            @unlink($path);
            $this->startFromSaved();

            throw new RuntimeException(
                $translator->trans('ernestdefoe-millwright.api.composer.resolve_gave_up', ['how' => $how, 'attempts' => self::RESOLVE_ATTEMPTS]).' '.$this->limitAdvice($prev)
            );
        }

        if (empty($prev['announced'])) {
            $prev['announced'] = true;
            @file_put_contents($path, json_encode($prev));

            throw new NotYet(
                $translator->trans('ernestdefoe-millwright.api.composer.resolve_retrying', ['how' => $how, 'attempt' => $attempts + 1, 'attempts' => self::RESOLVE_ATTEMPTS])
            );
        }
    }

    /** @param array<string,mixed> $attempt */
    protected function howItStopped(array $attempt): string
    {
        $translator = $this->run->translator;
        $seconds = (int) ($attempt['seconds'] ?? 0);

        return match ($attempt['stopped'] ?? null) {
            'time' => $translator->trans('ernestdefoe-millwright.api.composer.stopped_time', ['seconds' => (int) ($attempt['timeLimit'] ?? $seconds)]),
            'memory' => $translator->trans('ernestdefoe-millwright.api.composer.stopped_memory', ['limit' => (string) ($attempt['memoryLimit'] ?? '?')]),
            'unknown' => $translator->trans('ernestdefoe-millwright.api.composer.stopped_unknown', ['seconds' => $seconds]),
            default => $translator->trans('ernestdefoe-millwright.api.composer.stopped'),
        };
    }

    /** @param array<string,mixed> $attempt */
    protected function limitAdvice(array $attempt): string
    {
        return match ($attempt['stopped'] ?? null) {
            'memory' => $this->run->translator->trans('ernestdefoe-millwright.api.composer.advice_memory'),
            default => $this->run->translator->trans('ernestdefoe-millwright.api.composer.advice_time'),
        };
    }

    /**
     * Raise the requirements this run was authorised to raise.
     *
     * composer.json is edited BEFORE the resolve, and only for packages the
     * admin was shown. `composer.json.before` is already saved by then, and
     * Rollback restores it, so an update that raises a pin and then fails puts
     * the requirement back with everything else.
     *
     * It raises to an EXACT version, never to a range. A site that pinned
     * `3.5.1` deliberately stays pinned at `3.6.0`; quietly converting it to
     * `^3.6` would hand back the drift the pin was there to stop, as a side
     * effect of an unrelated click.
     *
     * @return list<string> what changed, for the log
     */
    public function raisePins(): array
    {
        $run = $this->run;

        if ($run->repin === [] || $run->mode !== 'update') {
            return [];
        }

        // Read and written through JsonFile, like every other composer.json
        // edit: a file it cannot parse is refused rather than rebuilt, and the
        // write goes beside the file and is renamed into place, so a run killed
        // mid-write cannot leave half a composer.json behind.
        $file = new JsonFile($run->translator, $run->installPath.'/composer.json');
        $json = $file->read();
        $raised = [];

        foreach ($run->repin as $package => $version) {
            $current = $json['require'][$package] ?? null;

            // Only ever touch a package this run was actually asked about, and
            // only one the site really does require.
            if (! is_string($current) || ! in_array($package, $run->requested, true)) {
                continue;
            }

            if ($current === $version) {
                continue;
            }

            $json['require'][$package] = $version;
            $raised[] = $run->translator->trans('ernestdefoe-millwright.api.composer.pin_raised', ['package' => $package, 'version' => $version, 'current' => $current]);
        }

        if ($raised === []) {
            return [];
        }

        $file->write($json);

        return $raised;
    }

    /**
     * Why an update changed nothing.
     *
     * "Everything is already at the newest version it can be" is true and
     * useless, and on a pinned forum it is actively misleading.
     *
     * A site that pins `ernestdefoe/page-builder` to `3.5.1` will be told by the
     * update check that 3.6.0 exists (correctly, it does), and pressing Update
     * then runs a resolve that cannot move it, because the constraint forbids
     * it. The run reported Finished, the card still said an update was
     * available, and nothing anywhere said why. Somebody would press it again.
     *
     * So when a requested package did not move, name its constraint. The admin
     * can then do the one thing that would help.
     */
    protected function whyNothingMoved(): string
    {
        $translator = $this->run->translator;
        $json = RunContext::readJson($this->run->installPath.'/composer.json');
        $pinned = [];

        foreach ($this->run->requested as $package) {
            $constraint = $json['require'][$package] ?? null;

            if (! is_string($constraint)) {
                continue;
            }

            /*
             * A constraint with no wildcard, caret or tilde admits exactly one
             * version, so a newer one can never satisfy it. Ranges are left out:
             * those genuinely can be "already newest".
             */
            if (preg_match('/^v?\d+\.\d+\.\d+/', $constraint) && ! preg_match('/[\^~*|]|\s-\s/', $constraint)) {
                $pinned[] = $translator->trans('ernestdefoe-millwright.api.composer.pinned_to', ['package' => $package, 'constraint' => $constraint]);
            }
        }

        if ($pinned === []) {
            return $translator->trans('ernestdefoe-millwright.api.composer.nothing_to_update');
        }

        return $translator->trans('ernestdefoe-millwright.api.composer.nothing_moved_pinned', ['pins' => implode('; ', $pinned)]);
    }

    /**
     * Did the command achieve what it was asked to, whatever it says?
     *
     * Only consulted when the exit code is non-zero, and only for a removal.
     * `composer remove --no-install` updates composer.json and the lock exactly
     * as wanted, and THEN checks whether the package has gone from vendor,
     * which it has not, because --no-install is us telling Composer not to touch
     * vendor. It reports "Removal failed, X is still present" and exits 1,
     * having done the job perfectly.
     *
     * Reading the outcome rather than the exit code is the honest way out. The
     * lock is the thing that matters, we already have it before and after, and
     * a removal that did not happen fails this check and reports Composer's own
     * words as before.
     *
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     */
    protected function didWhatWasAsked(array $before, array $after): bool
    {
        if ($this->run->mode !== 'remove' || $this->run->requested === []) {
            return false;
        }

        $names = fn (array $lock) => array_column((array) ($lock['packages'] ?? []), 'name');

        $was = $names($before);
        $now = $names($after);

        foreach ($this->run->requested as $package) {
            if (! in_array($package, $was, true) || in_array($package, $now, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Refuse before anything is downloaded or moved if the run would hit a file
     * it cannot change. See Permissions for the two half-finished runs this
     * replaces. Skipped, not passed, when the paths were never supplied.
     */
    public function checkPermissions(): string
    {
        $run = $this->run;

        if ($run->vendorPath === '' || $run->storagePath === '') {
            return $run->translator->trans('ernestdefoe-millwright.api.composer.permissions_not_checked');
        }

        // A root-owned cache is moved aside rather than refused; see StaleCache.
        $setAside = StaleCache::describe(StaleCache::setAside($run->storagePath), $run->translator);

        $permissions = new Permissions($run->translator, $run->installPath, $run->vendorPath, $run->storagePath);
        $blocked = $permissions->blocked($run->plan());

        if ($blocked !== []) {
            // The resolve has already rewritten these; put them back so the
            // refusal's "Nothing was changed" is true and no rollback is owed.
            foreach (['composer.lock', 'composer.json'] as $file) {
                $saved = $run->workDir.'/'.$file.'.before';
                if (is_file($saved)) {
                    @copy($saved, $run->installPath.'/'.$file);
                }
            }

            throw new RuntimeException($permissions->explain($blocked));
        }

        return $run->translator->trans('ernestdefoe-millwright.api.composer.permissions_ok').($setAside !== '' ? '. '.$setAside : '');
    }
}
