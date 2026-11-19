<?php

namespace ErnestDefoe\Millwright\Host;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\ResponseInterface;

/**
 * Reading public metadata over HTTPS: Packagist's package files, a
 * repository's composer.json.
 *
 * Through Guzzle rather than file_get_contents(), because shared hosts that
 * turn off allow_url_fopen are common, and there every one of those reads
 * failed silently and looked like "not on Packagist".
 *
 * Many at once, within a time limit, because "Check now" and starting a
 * nightly run ask about every installed package inside a web request. One
 * after another, a slow Packagist made that request as long as forty timeouts.
 */
class Web
{
    public const AGENT = 'Millwright (Flarum extension updater)';

    public function __construct(protected ClientInterface $http)
    {
    }

    /** The client itself, for callers that need headers or auth of their own. */
    public function client(): ClientInterface
    {
        return $this->http;
    }

    /** The body of a 2xx answer, or null for anything else. */
    public function get(string $url, int $timeout = 15): ?string
    {
        return $this->getMany(['one' => $url], $timeout, $timeout)['one'] ?? null;
    }

    /**
     * Several URLs at once. Each key maps to its 2xx body or null; a key whose
     * request was never started because $seconds ran out is left out, so a
     * caller can tell "not asked" from "asked and missing".
     *
     * @param array<string,string> $urls key => URL
     * @return array<string,?string>
     */
    public function getMany(array $urls, float $seconds, int $timeout = 15, int $concurrency = 8): array
    {
        $deadline = microtime(true) + $seconds;
        $keys = array_keys($urls);
        $bodies = [];

        $requests = function () use ($urls, $deadline) {
            foreach ($urls as $url) {
                // Checked before each request starts: what is already in flight
                // finishes, and nothing new begins after the limit.
                if (microtime(true) >= $deadline) {
                    return;
                }

                yield new Request('GET', $url, ['User-Agent' => self::AGENT]);
            }
        };

        (new Pool($this->http, $requests(), [
            'concurrency' => $concurrency,
            'options' => ['timeout' => $timeout, 'connect_timeout' => min(10, $timeout), 'http_errors' => false],
            'fulfilled' => function (ResponseInterface $response, int $index) use ($keys, &$bodies): void {
                $ok = $response->getStatusCode() >= 200 && $response->getStatusCode() < 300;
                $bodies[$keys[$index]] = $ok ? (string) $response->getBody() : null;
            },
            // Unreachable, refused, timed out: all "no answer", which every
            // caller already handles as "could not check".
            'rejected' => function ($reason, int $index) use ($keys, &$bodies): void {
                $bodies[$keys[$index]] = null;
            },
        ]))->promise()->wait();

        return $bodies;
    }
}
