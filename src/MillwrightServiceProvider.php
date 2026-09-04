<?php

namespace ErnestDefoe\Millwright;

use ErnestDefoe\Millwright\Apply\Applier;
use ErnestDefoe\Millwright\Apply\Journal;
use ErnestDefoe\Millwright\Run\Drivers;
use ErnestDefoe\Millwright\Run\RunStore;
use ErnestDefoe\Millwright\Run\StepRunner;
use ErnestDefoe\Millwright\Run\Steps;
use ErnestDefoe\Millwright\Work\ComposerRunner;
use ErnestDefoe\Millwright\Work\ComposerSteps;
use ErnestDefoe\Millwright\Work\Fetcher;
use ErnestDefoe\Millwright\Work\WorkDir;
use Flarum\Foundation\AbstractServiceProvider;
use Flarum\Foundation\Paths;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\Factory as QueueFactory;

class MillwrightServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(RunStore::class, function ($container) {
            /*
             * Under storage/, which is already writable, already excluded from
             * the web root, and already backed up with the rest of the site. A
             * run's state is not secret but it is not public either.
             */
            return new RunStore($container->make(Paths::class)->storage . '/millwright/runs');
        });

        $this->container->singleton(Drivers::class, function ($container) {
            return new Drivers(
                $container->make(QueueFactory::class),
                $container->make(Dispatcher::class)
            );
        });

        /*
         * 🚨 Steps are built for the run CURRENTLY in flight, because every path
         * they need — the plan, the staging area, the journal — is that run's own
         * scratch space. There is one active run at a time by design, so "the
         * latest run" is unambiguous, and a driver that knows only an id can
         * still reconstruct everything it needs.
         */
        $this->container->singleton(Steps::class, function ($container) {
            $paths   = $container->make(Paths::class);
            $run     = $container->make(RunStore::class)->latest();
            $workDir = new WorkDir($paths->storage, $run?->id ?? 'none');
            $journal = new Journal($workDir->journalPath());

            return new ComposerSteps(
                $paths->base,
                $workDir->root(),
                new ComposerRunner($paths->base, null, $paths->storage . '/.composer'),
                new Fetcher($workDir->staging(), $paths->base . '/auth.json'),
                new Applier($paths->vendor, $workDir->staging(), $workDir->trash(), $journal),
                $journal,
                $workDir->requested()
            );
        });

        /*
         * 🚨 The lock directory is what stops two drivers doing the same item.
         * On a forum with a queue, the admin page polling and a worker WILL run
         * at once — without this they both read the same index and a package is
         * applied twice.
         */
        $this->container->singleton(StepRunner::class, function ($container) {
            $storage = $container->make(Paths::class)->storage;

            return new StepRunner(
                $container->make(RunStore::class),
                $container->make(Steps::class),
                fn () => time(),
                $storage . '/millwright/locks'
            );
        });
    }
}
