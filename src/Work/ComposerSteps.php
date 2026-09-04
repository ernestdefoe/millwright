<?php

namespace ErnestDefoe\Millwright\Work;

use ErnestDefoe\Millwright\Apply\Applier;
use ErnestDefoe\Millwright\Apply\Journal;
use ErnestDefoe\Millwright\Plan\Change;
use ErnestDefoe\Millwright\Plan\LockDiff;
use ErnestDefoe\Millwright\Run\Run;
use ErnestDefoe\Millwright\Host\PhpBinary;
use ErnestDefoe\Millwright\Run\Steps;
use RuntimeException;

/**
 * A real update, broken into pieces small enough that no single one needs a long
 * request.
 *
 * This is where the four phases stop being an idea and become Composer, HTTP and
 * renames. Nothing here is clever: the difficulty was all in making each unit
 * small, repeatable and honest, and that work is in LockDiff, Fetcher and
 * Applier. This class only says what the units are and in what order.
 *
 * 🚨 Every doItem() has to be safe to run twice. The driver saves progress AFTER
 * the work, so a process killed in between asks for the same item again on
 * resume. Fetch checks whether the package is already staged; apply's stash is a
 * no-op when the source is already gone; the finalise commands are all
 * idempotent by nature. That is a property to preserve, not an accident.
 */
class ComposerSteps implements Steps
{
    public function __construct(
        private string $installPath,
        private string $workDir,
        private ComposerRunner $composer,
        private Fetcher $fetcher,
        private Applier $applier,
        private Journal $journal,
        /** @var list<string> the packages the user asked to change */
        private array $requested = [],
    ) {
    }

    public function itemsFor(string $phase, Run $run): array
    {
        return match ($phase) {
            'plan'     => ['work out what changes'],
            'fetch'    => array_map(fn (Change $c) => $c->package, $this->downloadable()),
            'apply'    => array_map(fn (Change $c) => $c->package, $this->plan()),
            'finalise' => ['autoloader', 'migrations', 'assets', 'caches'],
            default    => [],
        };
    }

    public function doItem(string $phase, string $item, Run $run): ?string
    {
        return match ($phase) {
            'plan'     => $this->resolve(),
            'fetch'    => $this->fetchOne($item),
            'apply'    => $this->applyOne($item),
            'finalise' => $this->finalise($item),
            default    => null,
        };
    }

    /**
     * Ask Composer what would change, and write it down.
     *
     * 🚨 `--no-install`: this updates composer.lock and NOTHING else. Composer's
     * own install would rebuild a tree; all that is wanted here is the answer to
     * "what moves", which is 16 seconds and 165 MB rather than minutes and a
     * mutated vendor directory. The install is ours to do, one package at a
     * time, after the user has seen the list.
     */
    private function resolve(): string
    {
        $lockPath = $this->installPath . '/composer.lock';
        $before   = $this->readJson($lockPath);

        /*
         * 🚨 Saved BEFORE Composer is allowed to rewrite them, and used by the
         * rollback. `--no-install` updates the lock the moment it succeeds, so
         * without a copy taken here there is no way back to the site's own
         * record of itself — and a rollback that restores the files but not the
         * lock leaves a site whose manifest describes work that was undone.
         */
        copy($lockPath, $this->workDir . '/composer.lock.before');
        copy($this->installPath . '/composer.json', $this->workDir . '/composer.json.before');

        $args = array_merge(['update'], $this->requested, ['--with-all-dependencies', '--no-install']);
        $result = $this->composer->run($args);

        if ($result['code'] !== 0) {
            // Composer's own words, not a summary of them: it is usually precise
            // about which constraint could not be satisfied, and paraphrasing
            // that loses the only part anyone can act on.
            throw new RuntimeException("Composer could not work out an update:\n" . $result['output']);
        }

        $after = $this->readJson($lockPath);

        /*
         * 🚨 The new lock is put back immediately. Composer has already written
         * it, and leaving it in place while the packages on disk are still the
         * old ones would mean a lock that disagrees with vendor/ — which the
         * next resolve would silently build on. The plan we just computed is the
         * record of what has to happen to make them agree again.
         */
        $changes = (new LockDiff())->between($before, $after);
        $sources = (new LockDiff())->sources($after);
        $reasons = (new LockDiff())->reasons($changes, $after, $this->requested);

        file_put_contents($this->workDir . '/plan.json', json_encode([
            'changes' => array_map(fn (Change $c) => $c->toArray(), $changes),
            'sources' => $sources,
            'reasons' => $reasons,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if ($changes === []) {
            return 'Nothing to update — everything is already at the newest version it can be.';
        }

        return count($changes) . ' package(s) will change';
    }

    private function fetchOne(string $package): string
    {
        $plan = $this->planFile();
        $source = $plan['sources'][$package] ?? null;

        if ($source === null) {
            throw new RuntimeException(
                "There is no downloadable archive for $package. It is probably installed from source or a path repository, which Millwright cannot fetch."
            );
        }

        $this->fetcher->fetch($package, $source);

        return "downloaded $package";
    }

    private function applyOne(string $package): string
    {
        foreach ($this->plan() as $change) {
            if ($change->package === $package) {
                return $this->applier->applyOne($change);
            }
        }

        throw new RuntimeException("$package is not in the plan.");
    }

    private function finalise(string $item): string
    {
        return match ($item) {
            'autoloader' => $this->composerCommand(['dump-autoload', '--optimize'], 'autoloader rebuilt'),
            'migrations' => $this->flarum('migrate', 'migrations run'),
            'assets'     => $this->flarum('assets:publish', 'assets published'),
            'caches'     => $this->flarum('cache:clear', 'caches cleared'),
            default      => 'nothing to do',
        };
    }

    private function composerCommand(array $args, string $note): string
    {
        $result = $this->composer->run($args);

        if ($result['code'] !== 0) {
            throw new RuntimeException("Composer failed:\n" . $result['output']);
        }

        return $note;
    }

    private function flarum(string $command, string $note): string
    {
        /*
         * 🚨 Run as a subprocess rather than in-process, and after the swap.
         * These boot Flarum, and booting it inside the request that just
         * replaced its files means loading a half-old, half-new class map. A
         * fresh process gets the tree as it now is.
         *
         * 🚨 Through the same PhpBinary the Composer phases use. When these
         * disagreed, an update could get through planning, downloading and the
         * swap — every expensive, risky part — and then fail on the last phase
         * because this one alone was spawned with a binary that cannot run a
         * script. Under FPM, PHP_BINARY is php-fpm.
         */
        $php = (new PhpBinary())->path();

        if ($php === null) {
            throw new RuntimeException(
                "The files are updated, but `flarum $command` could not be run: no command-line PHP was found on "
                . 'this host. Run it yourself, or ask your host where the PHP CLI binary lives.'
            );
        }

        $result = Process::run([$php, $this->installPath . '/flarum', $command], $this->installPath);

        if ($result['code'] !== 0) {
            $lines = explode("\n", $result['output']);

            throw new RuntimeException("`flarum $command` failed:\n" . implode("\n", array_slice($lines, -12)));
        }

        return $note;
    }

    /** @return list<Change> */
    private function plan(): array
    {
        return array_map(
            fn (array $row) => Change::fromArray($row),
            $this->planFile()['changes'] ?? []
        );
    }

    /** @return list<Change> */
    private function downloadable(): array
    {
        $sources = $this->planFile()['sources'] ?? [];

        return array_values(array_filter(
            $this->plan(),
            fn (Change $c) => $c->op !== Change::REMOVE && isset($sources[$c->package])
        ));
    }

    /** @return array<string,mixed> */
    private function planFile(): array
    {
        $path = $this->workDir . '/plan.json';

        if (! is_file($path)) {
            return [];
        }

        return (array) json_decode((string) file_get_contents($path), true);
    }

    /** @return array<string,mixed> */
    private function readJson(string $path): array
    {
        return (array) json_decode((string) file_get_contents($path), true);
    }
}
