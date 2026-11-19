<?php

namespace ErnestDefoe\Millwright\Work;

use ErnestDefoe\Millwright\Run\Run;
use Flarum\Foundation\Paths;
use Flarum\Locale\TranslatorInterface;
use Throwable;

/**
 * The second half of an undo: reversing the database, in a request booted
 * from the restored code. See MigrationLedger for why it cannot happen in the
 * undo request itself. Called by the admin page's next state read, so it
 * finishes on whichever request the page makes after the undo, even if it
 * was closed and opened again.
 */
class PendingUndo
{
    /** @return string|null why it could not finish, or null when there was nothing to do or it is done */
    public static function finish(TranslatorInterface $translator, ?Run $run, Paths $paths, Forum $forum): ?string
    {
        if ($run === null || $run->state !== Run::ROLLBACK) {
            return null;
        }

        $ledger = new MigrationLedger($translator, (new WorkDir($translator, $paths->storage, $run->id))->root());

        if (! $ledger->pending()) {
            return null;
        }

        try {
            $ledger->finish($forum->db, $forum->migrator, $forum->extensions, $paths->vendor);

            return null;
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }
}
