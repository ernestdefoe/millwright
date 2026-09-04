<?php

namespace ErnestDefoe\Millwright;

use ErnestDefoe\Millwright\Run\RunStore;
use Flarum\Foundation\AbstractServiceProvider;
use Flarum\Foundation\Paths;

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
    }
}
