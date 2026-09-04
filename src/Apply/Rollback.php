<?php

namespace ErnestDefoe\Millwright\Apply;

use ErnestDefoe\Millwright\Plan\Change;
use RuntimeException;

/**
 * Undoes an apply by reading its journal backwards.
 *
 * 🚨 It must work on a journal whose last entry is `begun` and not `done` —
 * that is the whole reason the journal is written before the act. That entry
 * describes a change that may have happened fully, partly, or not at all, and
 * the on-disk state is the only authority. So every step here is written to be
 * true whichever it was:
 *
 *   - restoring from the trash is skipped if the trash slot is empty
 *   - removing an added package is skipped if it is not there
 *   - a live directory is moved aside rather than deleted, even during a
 *     rollback, so a mistake here is still recoverable by hand
 *
 * Cost is proportional to the change, not the tree. Rolling back a
 * one-extension update moves one directory, which is why this works on a host
 * with a disk quota where copying vendor would not.
 */
class Rollback
{
    public function __construct(
        private string $vendorDir,
        private string $trashDir,
        private Journal $journal,
    ) {
    }

    /**
     * @return list<string> what was undone, newest first
     */
    public function run(): array
    {
        $entries = $this->journal->entries();

        if ($entries === []) {
            return [];
        }

        $undone = [];

        // Backwards: the last thing done is the first thing undone.
        foreach (array_reverse($entries) as $entry) {
            if (! isset($entry['change'])) {
                continue;
            }

            $change = Change::fromArray((array) $entry['change']);
            $trash  = (string) ($entry['trash'] ?? $change->trashName());

            match ($change->op) {
                Change::REPLACE => $this->restore($change, $trash),
                Change::ADD     => $this->uninstall($change),
                Change::REMOVE  => $this->restore($change, $trash),
            };

            $undone[] = $change->describe();
        }

        return $undone;
    }

    /** Put the stashed version back, discarding whatever is live. */
    private function restore(Change $change, string $trashName): void
    {
        $live   = $this->path($this->vendorDir, $change->relativePath());
        $stash  = $this->path($this->trashDir, $trashName);

        if (! is_dir($stash)) {
            /*
             * The interrupted case: the process died before the stash, so the
             * live copy is still the original and there is nothing to restore.
             * Doing nothing is correct — and touching the live directory here
             * would destroy the very thing being recovered.
             */
            return;
        }

        if (is_dir($live)) {
            // The new version. Moved aside, not deleted — see the class comment.
            $aside = $this->path($this->trashDir, $change->trashName() . '.rolledback');
            $this->deleteTree($aside);

            if (! @rename($live, $aside)) {
                throw new RuntimeException("Could not move $live aside during rollback");
            }
        }

        $this->ensureDir(dirname($live));

        if (! @rename($stash, $live)) {
            throw new RuntimeException("Could not restore $stash to $live");
        }
    }

    /** Take out a package this apply had added. */
    private function uninstall(Change $change): void
    {
        $live = $this->path($this->vendorDir, $change->relativePath());

        if (! is_dir($live)) {
            // Never installed — the apply died before this step.
            return;
        }

        $aside = $this->path($this->trashDir, $change->trashName() . '.rolledback');
        $this->deleteTree($aside);
        $this->ensureDir(dirname($aside));

        if (! @rename($live, $aside)) {
            throw new RuntimeException("Could not remove $live during rollback");
        }
    }

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
