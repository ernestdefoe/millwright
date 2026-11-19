<?php

namespace ErnestDefoe\Millwright;

use ErnestDefoe\Millwright\Host\StorageDir;
use ErnestDefoe\Millwright\Run\Drivers;
use ErnestDefoe\Millwright\Run\RunStore;
use ErnestDefoe\Millwright\Run\StepRunner;
use ErnestDefoe\Millwright\Run\StepsFactory;
use ErnestDefoe\Millwright\Work\ComposerStepsFactory;
use ErnestDefoe\Millwright\Work\Forum;
use GuzzleHttp\Client;
use Flarum\Foundation\AbstractServiceProvider;
use Flarum\Foundation\Config;
use Flarum\Foundation\Paths;
use Flarum\Locale\TranslatorInterface;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Psr\Log\LoggerInterface;

class MillwrightServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->honourWebReset();
        $this->registerRuns();
        $this->registerSteps();
        $this->registerHttp();
        $this->registerSite();
    }

    /**
     * The forum's own files and settings, as the screens and commands read
     * them: Composer's config, the host check, the update check's cache and
     * the trash. Bound, not shared: each is a thin view over files that change
     * under it, so every request gets one that reads them fresh.
     */
    protected function registerSite(): void
    {
        $storage = fn ($container) => $container->make(Paths::class)->storage;
        $json = fn ($container, string $file) => new \ErnestDefoe\Millwright\Config\JsonFile($container->make(TranslatorInterface::class), $container->make(Paths::class)->base.'/'.$file);

        $this->container->bind(\ErnestDefoe\Millwright\Work\UpdateCheck::class, fn ($container) => new \ErnestDefoe\Millwright\Work\UpdateCheck(
            StorageDir::of($storage($container)).'/updates.json'
        ));

        $this->container->bind(\ErnestDefoe\Millwright\Plan\Repin::class, fn ($container) => new \ErnestDefoe\Millwright\Plan\Repin(
            $container->make(Paths::class)->base.'/composer.json',
            $container->make(\ErnestDefoe\Millwright\Work\UpdateCheck::class)
        ));

        $this->container->bind(\ErnestDefoe\Millwright\Host\PhpOverride::class, fn ($container) => new \ErnestDefoe\Millwright\Host\PhpOverride(
            $container->make(TranslatorInterface::class),
            $storage($container)
        ));

        $this->container->bind(\ErnestDefoe\Millwright\Host\Capability::class, fn ($container) => new \ErnestDefoe\Millwright\Host\Capability(
            $container->make(TranslatorInterface::class),
            $container->make(Paths::class)->base,
            \ErnestDefoe\Millwright\Host\PhpBinary::forStorage($container->make(TranslatorInterface::class), $storage($container)),
            $storage($container).'/.composer'
        ));

        $this->container->bind(\ErnestDefoe\Millwright\Prune\Retention::class, fn ($container) => new \ErnestDefoe\Millwright\Prune\Retention(
            StorageDir::of($storage($container))
        ));

        $this->container->bind(\ErnestDefoe\Millwright\Prune\Pruner::class, fn ($container) => new \ErnestDefoe\Millwright\Prune\Pruner(
            $container->make(TranslatorInterface::class),
            StorageDir::of($storage($container)),
            $container->make(\ErnestDefoe\Millwright\Prune\Retention::class)
        ));

        $this->container->bind(\ErnestDefoe\Millwright\Config\Repositories::class, fn ($container) => new \ErnestDefoe\Millwright\Config\Repositories(
            $container->make(TranslatorInterface::class),
            $json($container, 'composer.json')
        ));

        $this->container->bind(\ErnestDefoe\Millwright\Config\Stability::class, fn ($container) => new \ErnestDefoe\Millwright\Config\Stability(
            $container->make(TranslatorInterface::class),
            $json($container, 'composer.json')
        ));

        $this->container->bind(\ErnestDefoe\Millwright\Work\ReleaseNotes::class, fn ($container) => new \ErnestDefoe\Millwright\Work\ReleaseNotes(
            StorageDir::of($storage($container)).'/release-notes',
            $container->make(\ErnestDefoe\Millwright\Config\AuthTokens::class),
            $container->make(\ErnestDefoe\Millwright\Host\Web::class)
        ));

        $this->container->bind(\ErnestDefoe\Millwright\Config\AuthTokens::class, fn ($container) => new \ErnestDefoe\Millwright\Config\AuthTokens(
            $container->make(TranslatorInterface::class),
            $json($container, 'auth.json')
        ));
    }

    /**
     * A CLI update asking the web server to drop its compiled code; see
     * Opcache::requestWebReset(). bootstrap/web-reset.php takes it before any
     * extension boots; this covers a site whose storage is not where that
     * file looks, and is not a second stat everywhere else.
     */
    protected function honourWebReset(): void
    {
        if (PHP_SAPI !== 'cli') {
            $storage = $this->container->make(Paths::class)->storage;

            if (! defined('MILLWRIGHT_EARLY_STORAGE') || $storage !== MILLWRIGHT_EARLY_STORAGE) {
                \ErnestDefoe\Millwright\Host\Opcache::honourWebReset($storage);
            }
        }
    }

    /** Where runs are kept, who drives them, and the lock that keeps two drivers apart. */
    protected function registerRuns(): void
    {
        $this->container->singleton(RunStore::class, function ($container) {
            /*
             * Under storage/, which is already writable, already excluded from
             * the web root, and already backed up with the rest of the site. A
             * run's state is not secret but it is not public either.
             */
            return new RunStore($container->make(TranslatorInterface::class), StorageDir::of($container->make(Paths::class)->storage).'/runs');
        });

        $this->container->singleton(Drivers::class, function ($container) {
            return new Drivers(
                $container->make(TranslatorInterface::class),
                $container->make(QueueFactory::class),
                $container->make(Dispatcher::class),
                $container->make(LoggerInterface::class)
            );
        });

        /*
         * The lock directory is what stops two drivers doing the same item.
         * On a forum with a queue, the admin page polling and a worker WILL run
         * at once — without this they both read the same index and a package is
         * applied twice.
         */
        $this->container->singleton(StepRunner::class, function ($container) {
            $storage = $container->make(Paths::class)->storage;

            return new StepRunner(
                $container->make(TranslatorInterface::class),
                $container->make(RunStore::class),
                $container->make(StepsFactory::class),
                fn () => time(),
                StorageDir::of($storage).'/locks'
            );
        });
    }

    protected function registerSteps(): void
    {
        /*
         * Bound as a FACTORY, never as the work for "the current run".
         *
         * The obvious binding — ask the store for the latest run and build the
         * work around it — is correct under PHP-FPM and wrong in a queue worker,
         * which is a long-lived process where a singleton outlives the job that
         * created it. That worker would carry the finished run's staging
         * directory and journal into the next run, and a journal that disagrees
         * with what happened is a rollback that restores the wrong files.
         *
         * Handing the id in at step() time removes the question entirely.
         */
        $this->container->singleton(ComposerStepsFactory::class, function ($container) {
            /*
             * Config is REQUIRED here, and this line is why.
             *
             * It used to be optional with a null default and this binding did
             * not pass it — so `siteUrl()` returned '' on every install, and
             * every run reported "No site address is configured, so this update
             * will not be judged by whether the site answers." The health check
             * and the automatic rollback-when-the-site-breaks were therefore
             * never once exercised, on any forum, since the day they were
             * written. Nothing errored; the feature simply was not there.
             */
            return new ComposerStepsFactory(
                $container->make(TranslatorInterface::class),
                $container->make(Paths::class),
                $container->make(Config::class),
                $container->make(Forum::class),
                $container->make(Client::class)
            );
        });

        $this->container->singleton(StepsFactory::class, fn ($container) => $container->make(ComposerStepsFactory::class));
    }

    protected function registerHttp(): void
    {
        /*
         * The HTTP client for Millwright's own classes only. A plain binding of
         * Guzzle's shared ClientInterface would hand this one to every other
         * extension that asks for it.
         */
        $this->container->when(\ErnestDefoe\Millwright\Host\Web::class)
            ->needs(\GuzzleHttp\ClientInterface::class)
            ->give(Client::class);

        // One search cache under storage/, shared by Find, the compatibility
        // check and the start-time refusal of a replaced package.
        $this->container->singleton(\ErnestDefoe\Millwright\Discover\Packagist::class, function ($container) {
            $web = $container->make(\ErnestDefoe\Millwright\Host\Web::class);

            return new \ErnestDefoe\Millwright\Discover\Packagist(
                $container->make(TranslatorInterface::class),
                new \ErnestDefoe\Millwright\Discover\Cache(StorageDir::of($container->make(Paths::class)->storage).'/packagist'),
                fn (string $url) => $web->get($url)
            );
        });
    }
}
