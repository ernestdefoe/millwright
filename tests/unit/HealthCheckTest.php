<?php

namespace ErnestDefoe\Millwright\Tests\Unit;

use ErnestDefoe\Millwright\Apply\Tree;
use ErnestDefoe\Millwright\Run\NotYet;
use ErnestDefoe\Millwright\Run\Run;
use ErnestDefoe\Millwright\Tests\unit\EnglishTranslator;
use ErnestDefoe\Millwright\Tests\unit\Fakes;
use ErnestDefoe\Millwright\Work\ComposerRunner;
use ErnestDefoe\Millwright\Work\RunContext;
use ErnestDefoe\Millwright\Work\SiteCheck;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * A site that does not answer is asked again on the next turn, never by
 * sleeping inside the request that is driving the run.
 */
class HealthCheckTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/mw-healthcheck-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/work', 0775, true);
        mkdir($this->dir.'/project', 0775, true);
        file_put_contents($this->dir.'/work/health.before', 'ok');
    }

    protected function tearDown(): void
    {
        Tree::delete($this->dir);
    }

    public function test_one_bad_answer_is_looked_at_again_on_the_next_turn(): void
    {
        $site = $this->site([new Response(502), new Response(200, [], 'example.test')]);

        $started = microtime(true);

        try {
            $site->verify(Run::start('r1', time()));
            $this->fail('one 502 decided the verdict');
        } catch (NotYet $e) {
            $this->assertStringContainsString('Looking again', $e->getMessage());
        }

        $this->assertLessThan(1.0, microtime(true) - $started, 'the request slept between looks');

        // The next turn: the site answers, and the update is not blamed.
        $this->assertStringContainsString('answer', $site->verify(Run::start('r1', time())));
        $this->assertFileDoesNotExist($this->dir.'/work/health.after.tries');
    }

    /** @param list<Response> $answers */
    private function site(array $answers): SiteCheck
    {
        $http = new Client(['handler' => HandlerStack::create(new MockHandler($answers))]);
        $base = Fakes::run($this->dir, new ComposerRunner(EnglishTranslator::make(), $this->dir.'/project', __FILE__, $this->dir.'/home'));

        $run = new RunContext(
            $base->translator, $base->installPath, $base->workDir, $base->composer, $base->journal, $base->forum,
            siteUrl: 'https://example.test/',
        );

        return new SiteCheck($run, $http);
    }
}
