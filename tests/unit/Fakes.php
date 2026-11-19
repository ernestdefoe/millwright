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
use Flarum\Foundation\Config;
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
     * A Forum on an in-memory SQLite database (or the one given). The
     * migrator and extension manager are inert: a test that reaches them is a
     * test that needs a real forum, and should build one.
     */
    public static function forum(?ConnectionInterface $db = null): Forum
    {
        if ($db === null) {
            $capsule = new Capsule();
            $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
            $db = $capsule->getConnection();
        }

        return new Forum(
            $db,
            (new ReflectionClass(Migrator::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(ExtensionManager::class))->newInstanceWithoutConstructor(),
            new Config(['url' => 'https://example.test']),
            new \Psr\Log\NullLogger(),
            new \Illuminate\Container\Container()
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
