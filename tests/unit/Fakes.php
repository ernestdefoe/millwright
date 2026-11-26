<?php

namespace ErnestDefoe\Millwright\Tests\unit;

use ErnestDefoe\Millwright\Apply\Applier;
use ErnestDefoe\Millwright\Apply\Journal;
use ErnestDefoe\Millwright\Work\ComposerRunner;
use ErnestDefoe\Millwright\Work\ComposerSteps;
use ErnestDefoe\Millwright\Work\Fetcher;
use ErnestDefoe\Millwright\Work\Forum;
use ErnestDefoe\Millwright\Work\RunContext;
use Flarum\Database\Migrator;
use Flarum\Extension\ExtensionManager;
use GuzzleHttp\Client;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\ConnectionInterface;
use ReflectionClass;

/**
 * The forum services and per-run wiring that tests building the classes by
 * hand need, in one place.
 */
class Fakes
{
    /**
     * A database for the tests that need one: in-memory SQLite by default, or
     * the MySQL or PostgreSQL that MW_TEST_DB names (`mysql`, `pgsql`), with
     * a table prefix as a real forum has. The tables a test creates are
     * dropped first, so each test starts clean.
     *
     * Set MW_TEST_DB_HOST, _PORT, _USER, _PASSWORD and _NAME to point it
     * elsewhere. The defaults are a local MySQL (root, no password) and a
     * throwaway PostgreSQL:
     *   docker run -d --name millwright_pg_test -e POSTGRES_PASSWORD=mwtest \
     *     -e POSTGRES_DB=millwright_test -p 127.0.0.1:56718:5432 postgres:18-alpine
     *
     * @param list<string> $tables dropped before the test creates them
     */
    public static function db(array $tables = []): ConnectionInterface
    {
        $driver = getenv('MW_TEST_DB') ?: 'sqlite';
        $env = fn (string $k, string $default) => getenv('MW_TEST_DB_'.$k) ?: $default;

        $config = match ($driver) {
            'mysql' => ['driver' => 'mysql', 'host' => $env('HOST', '127.0.0.1'), 'port' => $env('PORT', '3306'), 'database' => $env('NAME', 'millwright_test'),
                'username' => $env('USER', 'root'), 'password' => $env('PASSWORD', ''), 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => 'mw_'],
            'pgsql' => ['driver' => 'pgsql', 'host' => $env('HOST', '127.0.0.1'), 'port' => $env('PORT', '56718'), 'database' => $env('NAME', 'millwright_test'),
                'username' => $env('USER', 'postgres'), 'password' => $env('PASSWORD', 'mwtest'), 'charset' => 'utf8', 'prefix' => 'mw_', 'schema' => 'public'],
            default => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'mw_'],
        };

        $capsule = new Capsule();
        $capsule->addConnection($config);
        $db = $capsule->getConnection();

        if ($db->getDriverName() !== $config['driver']) {
            throw new \RuntimeException("Asked for {$config['driver']}, got {$db->getDriverName()}");
        }

        foreach ($tables as $table) {
            $db->getSchemaBuilder()->dropIfExists($table);
        }

        return $db;
    }

    /**
     * A Forum on the test database (see db()), or the one given. The
     * migrator and extension manager are inert: a test that reaches them is a
     * test that needs a real forum, and should build one.
     */
    public static function forum(?ConnectionInterface $db = null): Forum
    {
        $db ??= self::db();

        return new Forum(
            $db,
            (new ReflectionClass(Migrator::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(ExtensionManager::class))->newInstanceWithoutConstructor(),
            new \Psr\Log\NullLogger(),
            new \Illuminate\Container\Container(),
            new \Flarum\Settings\DatabaseSettingsRepository($db)
        );
    }

    /**
     * A run over `$dir/project` with its scratch space in `$dir/work`.
     *
     * @param list<string> $requested
     * @param array<string,string> $repin
     */
    public static function run(
        string $dir,
        ComposerRunner $composer,
        array $requested = [],
        string $mode = 'update',
        string $vendorPath = '',
        array $repin = [],
        ?Forum $forum = null,
        string $installPath = '',
        array $allow = [],
    ): RunContext {
        return new RunContext(
            EnglishTranslator::make(),
            $installPath !== '' ? $installPath : $dir.'/project',
            $dir.'/work',
            $composer,
            new Journal(EnglishTranslator::make(), $dir.'/work/journal.jsonl'),
            $forum ?? self::forum(),
            $requested,
            $mode,
            $vendorPath,
            repin: $repin,
            allow: $allow,
        );
    }

    public static function steps(RunContext $run): ComposerSteps
    {
        return ComposerSteps::for(
            $run,
            new Fetcher(EnglishTranslator::make(), new Client(), $run->workDir.'/staging'),
            new Applier(EnglishTranslator::make(), $run->installPath.'/vendor', $run->workDir.'/staging', $run->workDir.'/trash', $run->journal),
            new Client()
        );
    }
}
