<?php

namespace ErnestDefoe\Millwright\Host;

use Flarum\Locale\TranslatorInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

/**
 * Flarum's own web updater, asked to run its migrations.
 *
 * The moment new core files land, the version in the code no longer matches the
 * one in the database, and Flarum answers every request (the forum, the API,
 * this extension's own step endpoint) with its "Update Flarum" page (503).
 * Found driving rc.8 → nightly through the web API, 2026-10-07: the run swapped
 * the files and could never reach its own migrations step again. The command
 * line is not gated, which is why the CLI test passed.
 *
 * That page has one way out, and it is Flarum's: POST the database credentials
 * from config.php and it runs `migrate`, in a fresh request with the new code.
 * On a host that cannot start a process, this asks the updater itself, from the
 * same request that swapped the files.
 *
 * The credentials themselves are never sent, to any address. The request
 * carries a one-time token instead (see UpdaterToken), which the receiving
 * request exchanges for the credentials on the server. The web server is asked
 * directly on 127.0.0.1 first, with the forum's host name, and the forum's own
 * address is used only when that fails and it is https.
 *
 * A site whose config.php or storage is not beside vendor/, where the
 * bootstrap looks, cannot redeem the token. There the bridge fails, and the
 * run puts the update back (see Finaliser::bridgeCore): a forum still on its
 * old version beats database credentials posted to whichever site answers on
 * 127.0.0.1, which on shared hosting may be somebody else's.
 */
class FlarumUpdater
{
    protected const TIMEOUT = 300;

    public function __construct(
        protected TranslatorInterface $translator,
        protected ClientInterface $http,
        protected string $siteUrl,
        protected string $storagePath,
    ) {
    }

    /** @return string what the updater said */
    public function migrate(): string
    {
        $host = (string) parse_url($this->siteUrl, PHP_URL_HOST);
        $port = parse_url($this->siteUrl, PHP_URL_PORT);
        $path = (string) (parse_url($this->siteUrl, PHP_URL_PATH) ?: '/');
        $loopback = 'http://127.0.0.1'.$path;
        $hostHeader = $host.($port ? ':'.$port : '');

        $local = $this->post($loopback, $hostHeader);

        if ($local['status'] === 200) {
            return $local['body'];
        }

        // Unreachable, or redirected to https (a site that forces it): ask the real address.
        $redirected = $local['status'] !== null && $local['status'] >= 300 && $local['status'] < 400;

        if (($local['status'] === null || $redirected) && str_starts_with($this->siteUrl, 'https://')) {
            $remote = $this->post($this->siteUrl, null);

            if ($remote['status'] === 200) {
                return $remote['body'];
            }

            $local = $remote;
        }

        throw new RuntimeException(
            $local['status'] === null
                ? $this->translator->trans('ernestdefoe-millwright.api.updater.unreachable', ['error' => $local['error']])
                : $this->translator->trans('ernestdefoe-millwright.api.updater.answered', ['status' => $local['status'], 'body' => mb_substr(trim(strip_tags($local['body'])), 0, 200)])
        );
    }

    /**
     * One POST to the updater, with a fresh one-time token.
     *
     * @return array{status:int|null, body:string, error:string}
     */
    protected function post(string $url, ?string $hostHeader): array
    {
        $headers = ['User-Agent' => 'Millwright', UpdaterToken::HEADER => UpdaterToken::issue($this->storagePath)]
            + ($hostHeader !== null ? ['Host' => $hostHeader] : []);

        try {
            $response = $this->http->request('POST', $url, [
                'form_params' => [],
                'headers' => $headers,
                'timeout' => self::TIMEOUT,
                'connect_timeout' => 8,
                'allow_redirects' => false,
                'http_errors' => false,
            ]);

            return ['status' => $response->getStatusCode(), 'body' => (string) $response->getBody(), 'error' => ''];
        } catch (GuzzleException $e) {
            return ['status' => null, 'body' => '', 'error' => $e->getMessage()];
        } finally {
            // Used or not, it is never left lying about.
            UpdaterToken::revoke($this->storagePath);
        }
    }
}
