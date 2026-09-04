<?php

namespace ErnestDefoe\Millwright\Apply;

use ErnestDefoe\Millwright\Plan\Change;
use RuntimeException;

/**
 * Puts a plan into the vendor tree, one package at a time, writing down each
 * step before it takes it.
 *
 * 🚨 Two renames, never a delete.
 *
 *     vendor/<pkg>   → trash/<pkg>@<version>     (the old one is kept)
 *     staging/<pkg>  → vendor/<pkg>              (the new one arrives)
 *
 * Both are `rename()`, so each is atomic and effectively instantaneous. The
 * package is absent between them, and only between them — microseconds, for one
 * package, against Extension Manager's window of deleting 1.3 GB and moving it
 * back. Nothing is deleted at all during an apply: the old tree waits in the
 * trash until somebody prunes it, which is what makes rollback possible long
 * after the fact.
 *
 * POSIX cannot swap two directories atomically, so two renames is the floor. It
 * is not worth reaching for something cleverer.
 *
 * The applier does not resolve, download, or decide anything. It is handed a
 * list and a staging directory, and its only job is to be interruptible.
 */
class Applier
{
    /**
     * @param ?callable $observer Called after each filesystem step with a label
     *        and the change. Serves two purposes, which is why it earns its
     *        place in production code: the step driver reports progress through
     *        it, and the crash tests kill the process from it, which is the only
     *        way to prove the journal survives an interruption at each point
     *        rather than at a convenient one.
     */
    public function __construct(
        private string $vendorDir,
        private string $stagingDir,
        private string $trashDir,
        private Journal $journal,
        private $observer = null,
    ) {
    }

    private function observe(string $label, Change $change): void
    {
        if ($this->observer !== null) {
            ($this->observer)($label, $change);
        }
    }

    /**
     * @param list<Change> $changes
     * @return list<string> what was applied, in order, for the caller to report
     */
    public function apply(array $changes): array
    {
        if ($this->journal->exists() && ! $this->journal->isComplete()) {
            /*
             * 🚨 Refuse rather than continue. A journal with an unfinished entry
             * means a previous apply died, and the tree is in a state only that
             * journal can explain. Starting a second apply on top of it would
             * interleave two records and make both unreadable — which is how a
             * recoverable situation becomes a manual one.
             */
            throw new RuntimeException(
                'A previous apply did not finish. Roll it back or resume it before starting another.'
            );
        }

        $this->ensureDir($this->trashDir);
        $done = [];

        foreach ($changes as $change) {
            $seq = $this->journal->begin([
                'change' => $change->toArray(),
                'trash'  => $change->trashName(),
            ]);

            $this->observe('journalled', $change);

            match ($change->op) {
                Change::REPLACE => $this->replace($change),
                Change::ADD     => $this->add($change),
                Change::REMOVE  => $this->remove($change),
            };

            $this->journal->complete($seq);
            $this->observe('completed', $change);
            $done[] = $change->describe();
        }

        return $done;
    }

    private function replace(Change $change): void
    {
        $this->stash($change);
        $this->observe('stashed', $change);

        $this->install($change);
        $this->observe('installed', $change);
    }

    private function add(Change $change): void
    {
        $this->install($change);
        $this->observe('installed', $change);
    }

    private function remove(Change $change): void
    {
        $this->stash($change);
        $this->observe('stashed', $change);
    }

    /** Move the installed version aside. Kept, never deleted. */
    private function stash(Change $change): void
    {
        $live = $this->path($this->vendorDir, $change->relativePath());

        if (! is_dir($live)) {
            /*
             * Already absent. Not an error: a resumed apply reaches here after
             * the stash it is repeating already happened, and a plan built
             * against a slightly stale tree can name a package somebody removed
             * by hand. Either way there is nothing to preserve.
             */
            return;
        }

        $target = $this->path($this->trashDir, $change->trashName());

        if (is_dir($target)) {
            // Left by an earlier attempt at this same change. The live copy is
            // the newer truth, so the stale stash goes.
            $this->deleteTree($target);
        }

        $this->ensureDir(dirname($target));

        if (! @rename($live, $target)) {
            throw new RuntimeException("Could not move $live aside to $target");
        }
    }

    /** Move the staged version into place. */
    private function install(Change $change): void
    {
        $staged = $this->path($this->stagingDir, $change->relativePath());

        if (! is_dir($staged)) {
            throw new RuntimeException(
                "Nothing staged for {$change->package} at $staged — fetch must run before apply"
            );
        }

        $live = $this->path($this->vendorDir, $change->relativePath());

        if (is_dir($live)) {
            /*
             * Only reachable on an ADD whose package already exists, or a resumed
             * REPLACE whose stash succeeded and whose install is being repeated.
             * Moving it aside rather than deleting keeps the no-deletes rule
             * intact even on the paths nobody expects to hit.
             */
            $orphan = $this->path($this->trashDir, $change->trashName() . '.superseded');
            $this->deleteTree($orphan);
            @rename($live, $orphan);
        }

        $this->ensureDir(dirname($live));

        if (! @rename($staged, $live)) {
            throw new RuntimeException("Could not move $staged into place at $live");
        }
    }

    /**
     * 🚨 Join a trusted base with a relative path that has already been
     * validated by Change::relativePath(). Kept in one place so there is one
     * thing to audit rather than five string concatenations.
     */
    private function path(string $base, string $relative): string
    {
        return rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $relative;
    }

    private function ensureDir(string $dir): void
    {
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException("Could not create $dir");
        }
    }

    private function deleteTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($dir);
    }
}
