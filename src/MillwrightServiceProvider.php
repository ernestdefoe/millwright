<?php

namespace ErnestDefoe\Millwright;

use ErnestDefoe\Millwright\Run\Drivers;
use ErnestDefoe\Millwright\Run\RunStore;
use ErnestDefoe\Millwright\Run\StepRunner;
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
         * 🚨 The lock directory is what stops two drivers doing the same item.
         * On a forum with a queue, the admin page polling and a worker WILL run
         * at once — without this they both read the same index and a package is
         * applied twice.
         */
        $this->container->singleton(StepRunner::class, function ($container) {
            $storage = $container->make(Paths::class)->storage;

            return new StepRunner(
                $container->make(RunStore::class),
                $container->make(\ErnestDefoe\Millwright\Run\Steps::class),
                fn () => time(),
                $storage . '/millwright/locks'
            );
        });
    }
}
