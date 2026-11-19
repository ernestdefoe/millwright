<?php

namespace ErnestDefoe\Millwright\Work;

use Flarum\Database\Migrator;
use Flarum\Extension\ExtensionManager;
use Flarum\Foundation\Config;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;

/**
 * The forum's own services that an update and an undo talk to.
 *
 * Injected once, where the run's apparatus is built, so nothing in the middle
 * of a run reaches into the container: the database the migration ledger
 * reads, the migrator that reverses what it recorded, the extension list it
 * matches migrations to, and the database settings Flarum's own updater is
 * handed on a host that cannot start a process, and the forum's log for what
 * goes wrong without stopping the run.
 */
class Forum
{
    public function __construct(
        public readonly ConnectionInterface $db,
        public readonly Migrator $migrator,
        public readonly ExtensionManager $extensions,
        public readonly Config $config,
        public readonly LoggerInterface $log,
    ) {
    }

    /** @return array<string,mixed> config.php's database block */
    public function database(): array
    {
        return (array) ($this->config['database'] ?? []);
    }
}
