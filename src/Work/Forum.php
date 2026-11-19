<?php

namespace ErnestDefoe\Millwright\Work;

use Flarum\Database\Migrator;
use Flarum\Extension\ExtensionManager;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;

/**
 * The forum's own services that an update and an undo talk to.
 *
 * Injected once, where the run's apparatus is built, so nothing in the middle
 * of a run reaches into the container: the database the migration ledger
 * reads, the migrator that reverses what it recorded, the extension list it
 * matches migrations to, the forum's log for what goes wrong without stopping
 * the run, and the container its console commands are built from when one has
 * to run inside this request.
 */
class Forum
{
    public function __construct(
        public readonly ConnectionInterface $db,
        public readonly Migrator $migrator,
        public readonly ExtensionManager $extensions,
        public readonly LoggerInterface $log,
        public readonly Container $container,
        public readonly SettingsRepositoryInterface $settings,
    ) {
    }

    /**
     * The version Flarum records in its database, read from the table itself.
     *
     * Not through the settings repository: this is read just after `flarum
     * migrate` ran in another process, and a settings cache (fof/redis caches
     * every setting) would still answer with the version from before. Changing
     * it goes through the repository, so that cache is updated too.
     */
    public function recordedVersion(): ?string
    {
        $value = $this->db->table('settings')->where('key', 'version')->value('value');

        return $value === null ? null : (string) $value;
    }
}
