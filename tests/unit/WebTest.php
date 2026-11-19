<?php

namespace ErnestDefoe\Millwright\Tests\Unit;

use ErnestDefoe\Millwright\Host\Web;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * Public metadata reads: through Guzzle, many at once, inside a time limit.
 */
class WebTest extends TestCase
{
    public function test_each_url_answers_with_its_body_or_null(): void
    {
        $web = $this->web([
            new Response(200, [], 'A'),
            new Response(404, [], 'not here'),
            new ConnectException('refused', new Request('GET', 'https://c.test')),
        ]);

        $this->assertSame(
            ['a' => 'A', 'b' => null, 'c' => null],
            $this->sorted($web->getMany(['a' => 'https://a.test', 'b' => 'https://b.test', 'c' => 'https://c.test'], 30))
        );
    }

    /**
     * "Check now" runs in a web request: once its time is up nothing new is
     * started, and the packages not asked about are left out rather than
     * reported as missing.
     */
    public function test_nothing_starts_after_the_time_limit(): void
    {
        $mock = new MockHandler([new Response(200, [], 'A')]);
        $web = new Web(new Client(['handler' => HandlerStack::create($mock)]));

        $this->assertSame([], $web->getMany(['a' => 'https://a.test', 'b' => 'https://b.test'], 0));
        $this->assertCount(1, $mock, 'a request was sent after the time limit');
    }

    /**
     * file_get_contents() on a URL fails silently wherever allow_url_fopen is
     * off, which is common on shared hosting, and reads as "not on Packagist".
     */
    public function test_no_url_is_read_with_file_get_contents(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/src', \FilesystemIterator::SKIP_DOTS)) as $file) {
            foreach (file($file->getPathname()) ?: [] as $n => $line) {
                if (preg_match('/file_get_contents\(\s*(\'https?:|"https?:|\$url\b)/', $line) || str_contains($line, 'stream_context_create')) {
                    $offenders[] = substr($file->getPathname(), strlen($root) + 1).':'.($n + 1);
                }
            }
        }

        $this->assertSame([], $offenders);
    }

    /** @param list<mixed> $responses */
    private function web(array $responses): Web
    {
        return new Web(new Client(['handler' => HandlerStack::create(new MockHandler($responses))]));
    }

    /** @param array<string,?string> $a */
    private function sorted(array $a): array
    {
        ksort($a);

        return $a;
    }
}
