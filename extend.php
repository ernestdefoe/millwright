<?php

use ErnestDefoe\Millwright\Api\Controller;
use ErnestDefoe\Millwright\Console\CheckCommand;
use ErnestDefoe\Millwright\Console\PruneCommand;
use ErnestDefoe\Millwright\Console\RebuildAssetsCommand;
use ErnestDefoe\Millwright\Console\RepairFormatterCommand;
use ErnestDefoe\Millwright\Console\UpdateCommand;
use ErnestDefoe\Millwright\Exception\RefusedException;
use ErnestDefoe\Millwright\Exception\RefusedExceptionHandler;
use ErnestDefoe\Millwright\MillwrightServiceProvider;
use Flarum\Extend;

/*
 * Plain files, not a Flarum filesystem disk, for everything Millwright keeps:
 * it moves packages in and out of vendor/, and keeps its own run state under
 * storage/millwright, written beside each file and renamed into place
 * (Host\StateFile) so a kill never leaves half a file. Both have to be readable
 * by whichever process carries a run on, some before Flarum has booted.
 */
return [
    (new Extend\ServiceProvider())
        ->register(MillwrightServiceProvider::class),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        // The chunks loaded on demand (the release notes window). Without this
        // Flarum never publishes them and the button 404s (ClaudiusH).
        ->jsDirectory(__DIR__.'/js/dist/admin')
        ->css(__DIR__.'/less/admin.less'),

    (new Extend\Console())
        ->command(CheckCommand::class)
        /*
         * Registered as a command so it runs in its OWN process, after the
         * files have moved. Repairing the formatter means building it, and
         * building it inside the request that just replaced Flarum's files
         * loads a half-old class map.
         */
        ->command(RepairFormatterCommand::class)
        ->command(RebuildAssetsCommand::class)
        /*
         * The same run the admin screen drives, with a terminal turning the
         * handle instead of a browser. Deliberately not scheduled, and never
         * will be: an update that starts itself is an update nobody chose to be
         * present for.
         */
        ->command(UpdateCommand::class)
        /*
         * Daily, and cheap enough to mean it. This is one HTTP call per
         * installed package with no Composer involved — a resolve on a schedule
         * would be 165 MB on somebody else's shared host, every night, for a
         * question they may not have asked.
         */
        ->schedule(CheckCommand::class, function ($event): void { $event->daily(); })
        /*
         * Tidies the rollback copies no rollback can reach. Also runs at the
         * end of every update; nightly catches the forum that has not updated
         * in a month and is still carrying the copies from the last one.
         *
         * NO arguments, and it must stay that way: a scheduled
         * `['--dry-run' => true]` renders as `--dry-run='1'`, which a no-value
         * option refuses — every night, into /dev/null.
         */
        ->command(PruneCommand::class)
        ->schedule(PruneCommand::class, function ($event): void { $event->dailyAt('04:20'); }),

    new Extend\Locales(__DIR__.'/resources/locale'),

    (new Extend\ErrorHandling())
        ->handler(RefusedException::class, RefusedExceptionHandler::class),

    /*
     * Actions, not JSON:API resources. Nothing here is a database model: a run
     * lives in files under storage/, and each route starts, steps, undoes or
     * reports on one. That is the shape of Flarum's own Extension Manager,
     * which registers its update actions the same way. Every one asserts admin.
     *
     * Two of them matter most, and the split between them is deliberate.
     *
     * `state` is a read: the admin screen polls it, and it must stay cheap
     * enough to poll every second or two without being noticed. The one thing
     * it finishes is the database half of an undo the admin already asked for
     * (see StateController).
     *
     * `step` does exactly one unit of work and returns. That is the whole
     * design — progress is a function of how many times this is called, so a
     * host that cuts every request at thirty seconds can still finish an update
     * that takes ten minutes. Nothing here ever loops.
     */
    (new Extend\Routes('api'))
        ->get('/millwright/state', 'millwright.state', Controller\StateController::class)
        ->post('/millwright/check', 'millwright.check', Controller\CheckController::class)
        ->get('/millwright/release-notes', 'millwright.release-notes', Controller\ReleaseNotesController::class)
        ->post('/millwright/update', 'millwright.update', Controller\StartController::class)
        ->post('/millwright/step', 'millwright.step', Controller\StepController::class)
        ->post('/millwright/rollback', 'millwright.rollback', Controller\RollbackController::class)
        /*
         * GET is a dry run (what a prune would free, for the confirm); the
         * other two prune or save the retention settings. Kept off /state,
         * which is polled during an update — sizing the trash walks every
         * file in it.
         */
        ->get('/millwright/trash', 'millwright.trash', Controller\TrashController::class)
        ->post('/millwright/trash/prune', 'millwright.trash.prune', Controller\PruneTrashController::class)
        ->patch('/millwright/trash/settings', 'millwright.trash.settings', Controller\TrashSettingsController::class)
        ->get('/millwright/core', 'millwright.core', Controller\CoreController::class)
        /*
         * GET reports where Composer looks, what it accepts and which hosts
         * have a credential — never the credentials themselves. Each change
         * has its own route, and each answers with the settings as they now are.
         */
        ->get('/millwright/config', 'millwright.config', Controller\ConfigController::class)
        ->post('/millwright/config/repositories', 'millwright.config.repositories.add', Controller\Config\AddRepositoryController::class)
        ->delete('/millwright/config/repositories', 'millwright.config.repositories.remove', Controller\Config\RemoveRepositoryController::class)
        ->patch('/millwright/config/stability', 'millwright.config.stability', Controller\Config\StabilityController::class)
        ->post('/millwright/config/auth', 'millwright.config.auth.set', Controller\Config\SetAuthController::class)
        ->delete('/millwright/config/auth', 'millwright.config.auth.remove', Controller\Config\RemoveAuthController::class)
        ->patch('/millwright/config/php', 'millwright.config.php', Controller\Config\PhpPathController::class)
        /*
         * Discovery is two endpoints, and the split is deliberate. `discover`
         * is one call to Packagist's search. `compat` is one call PER PACKAGE to
         * work out whether each result fits the Flarum actually installed —
         * doing both in one request would mean a dozen round trips before
         * anything appeared on a screen somebody is typing into.
         */
        ->get('/millwright/discover', 'millwright.discover', Controller\DiscoverController::class)
        ->post('/millwright/discover/compat', 'millwright.compat', Controller\CompatController::class),
];
