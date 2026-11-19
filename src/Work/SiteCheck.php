<?php

namespace ErnestDefoe\Millwright\Work;

use ErnestDefoe\Millwright\Host\ErrorLog;
use ErnestDefoe\Millwright\Host\Opcache;
use ErnestDefoe\Millwright\Host\SiteHealth;
use ErnestDefoe\Millwright\Host\Verdict;
use ErnestDefoe\Millwright\Plan\CoreOverrides;
use ErnestDefoe\Millwright\Run\NotYet;
use ErnestDefoe\Millwright\Run\Reverted;
use ErnestDefoe\Millwright\Run\Run;
use GuzzleHttp\ClientInterface;
use RuntimeException;

/**
 * Is the site answering, before and after, and is the web server running the
 * code this run put in place?
 *
 * The before is what makes the after mean anything: an update is only blamed
 * for a site that worked when it started. The code-cache wait is what makes
 * "Finished" mean "in effect".
 */
class SiteCheck
{
    public function __construct(
        protected RunContext $run,
        protected ClientInterface $http,
    ) {
    }

    /**
     * What the site was doing BEFORE we touched anything.
     *
     * Without this, an automatic rollback is a liability rather than a
     * safety net. A site that was already down for its own reasons (a bad
     * config, a full disk, a database that went away) would fail the check
     * afterwards too, and the update would be blamed and undone for a fault it
     * had nothing to do with. Worse, so would a host where the check itself
     * cannot run: no curl, no outbound DNS, a URL that resolves to somewhere
     * else. On any of those the honest answer is "this update will not be
     * judged by whether the site answers", and the only way to know is to have
     * asked before.
     */
    public function baseline(): string
    {
        $translator = $this->run->translator;
        $health = $this->health();

        if ($health === null) {
            $this->rememberHealth('unchecked');

            return $translator->trans('ernestdefoe-millwright.api.composer.health_no_address');
        }

        $result = $this->look($health, 'before', 2);
        $this->rememberHealth($result['ok'] ? 'ok' : 'down');

        return $result['ok']
            ? $translator->trans('ernestdefoe-millwright.api.composer.health_ok_before')
            : $translator->trans('ernestdefoe-millwright.api.composer.health_down_before', ['why' => $result['why']]);
    }

    /**
     * Did this update break the site? If so, put it back and say why.
     *
     * This is the last check on purpose. It runs after the code cache step
     * has waited for the web tier to be serving the new files, so what it asks
     * is a real question: a check that ran before then would be asking whether
     * the OLD code still works, and would pass every time.
     */
    public function verify(Run $run): string
    {
        $translator = $this->run->translator;
        $health = $this->health();
        $before = $this->rememberedHealth();

        if ($health === null || $before === 'unchecked') {
            return $translator->trans('ernestdefoe-millwright.api.composer.health_not_checked');
        }

        $result = $this->look($health, 'after', 3);

        /*
         * Flarum answers 503 with its "Update Flarum" page while php-fpm is
         * still running the old code against a database the update already
         * migrated. That's the web server not having re-read the files yet,
         * not a broken update. Within the window it can take, wait and look
         * again rather than put back an update that is about to work.
         */
        if (! $result['ok'] && ($result['status'] ?? null) === 503) {
            $situation = (new Opcache($translator))->situation();
            $freq = (int) ($situation['freq'] ?? 0);

            if ($situation['validates'] && $freq > 0 && $freq <= Opcache::WAIT_CAP && time() < $this->codeLiveAt($freq) + $freq) {
                throw new NotYet($translator->trans('ernestdefoe-millwright.api.composer.health_503_waiting'));
            }
        }

        return match (Verdict::from($before, $result['ok'])) {
            Verdict::HEALTHY => $translator->trans('ernestdefoe-millwright.api.composer.health_ok_after'),
            Verdict::ALREADY_BROKEN => $translator->trans('ernestdefoe-millwright.api.composer.health_still_down'),
            Verdict::NOT_JUDGED => $translator->trans('ernestdefoe-millwright.api.composer.health_not_checked'),
            // It worked before and it does not now. The update is the difference.
            Verdict::BROKEN_BY_THIS => $this->putBack($run, $result['why']),
        };
    }

    /**
     * Ask the site, and when it does not answer, ask again on a later turn
     * rather than sleeping in this one.
     *
     * php-fpm workers do not all pick up new code in the same instant, and one
     * unlucky request is not an outage; a few looks a couple of seconds apart
     * is. The pause is the driver's: the admin page polls every couple of
     * seconds, a queue worker waits between turns, the command line pauses.
     *
     * @return array{ok:bool, status:int|null, why:string}
     */
    protected function look(SiteHealth $health, string $when, int $looks): array
    {
        $result = $health->check();
        $tries = $this->run->workDir.'/health.'.$when.'.tries';
        $tried = (int) @file_get_contents($tries) + 1;

        if (! $result['ok'] && $tried < $looks) {
            @file_put_contents($tries, (string) $tried);

            throw new NotYet($this->run->translator->trans('ernestdefoe-millwright.api.composer.health_looking_again', ['why' => $result['why']]));
        }

        @unlink($tries);

        return $result;
    }

    /**
     * Undo the update that broke the site, and say what the error was.
     *
     * Always throws: Reverted when the undo ran, a plain failure when there is
     * nothing to undo with.
     */
    protected function putBack(Run $run, string $why): never
    {
        $translator = $this->run->translator;
        $logged = $this->run->storagePath === ''
            ? null
            : (new ErrorLog($this->run->storagePath))->latest(max(1, $run->startedAt));

        $said = $logged === null
            ? $why
            : $why.' '.$translator->trans('ernestdefoe-millwright.api.composer.the_error_was', ['error' => $logged]);

        /*
         * When the error names one of Flarum's classes and an extension ships
         * its own copy of that class, say so. "Call to undefined method
         * Flarum\...\LessCompiler::fontRevisionIn()" reads like Flarum or this
         * updater is broken; the real cause was irmmr/flarum-ext-rtl's frozen
         * copy of LessCompiler (ClaudiusH, 2026-10-18).
         */
        $culprit = CoreOverrides::culpritFor((string) $logged.' '.$why, $this->coreOverrides());

        if ($culprit !== null) {
            $said .= ' '.$translator->trans('ernestdefoe-millwright.api.composer.replaced_core_class', $culprit);
        }

        $restore = $this->run->restore();

        if ($restore === null || ! $restore->possible()) {
            throw new RuntimeException(
                $translator->trans('ernestdefoe-millwright.api.composer.broke_site_no_restore').' '.$said
            );
        }

        // Putting it back runs Composer, which must not happen in-process
        // inside a long-lived process holding the update's classes.
        $this->run->needsFreshCode();

        $done = $restore->run();

        $message = $translator->trans('ernestdefoe-millwright.api.composer.broke_site_restored').' '.$said;

        if ($done['note'] !== null) {
            $message .= ' '.$done['note'];
        }

        throw new Reverted($message, $done['undone']);
    }

    /** @return list<array{package:string, classes:list<string>}> */
    protected function coreOverrides(): array
    {
        $lock = (array) json_decode((string) @file_get_contents($this->run->installPath.'/composer.lock'), true);

        return (new CoreOverrides())->find(array_values((array) ($lock['packages'] ?? [])), $this->run->vendor());
    }

    /**
     * From the command line, ask the web server to reset its compiled code and
     * wait briefly for a request to take it up. True when it did. On the web
     * this process can reset its own, so there is nothing to ask.
     *
     * The wait is CLI only (the PHP_SAPI check is the first thing here): a
     * worker or `php flarum millwright:update` may pause half a second between
     * looks, a web request never reaches the loop.
     */
    public function clearWebCache(): bool
    {
        if (PHP_SAPI !== 'cli' || $this->run->storagePath === '' || ($health = $this->health()) === null) {
            return false;
        }

        Opcache::requestWebReset($this->run->storagePath);

        for ($i = 0; $i < 10; $i++) {
            $health->check();   // any answer will do; booting Flarum takes the flag

            if (! Opcache::webResetPending($this->run->storagePath)) {
                return true;
            }

            usleep(500_000);
        }

        return false;
    }

    /**
     * Straight after the files change, not at the end of the run.
     *
     * With revalidate_freq=60 the web server kept running a package's OLD
     * compiled extend.php against its NEW files for every step between the swap
     * and 'code cache'. fof/best-answer 2.0.0 deleted a service provider its
     * beta still registered, and every page and the admin panel on fbsfb.com
     * answered "class not found" until the cache was cleared (2026-10-15).
     */
    public function dropStaleCode(): void
    {
        if (PHP_SAPI !== 'cli') {
            (new Opcache($this->run->translator))->clear();
        } else {
            $this->clearWebCache();
        }
    }

    protected function health(): ?SiteHealth
    {
        return $this->run->siteUrl === '' ? null : new SiteHealth($this->run->translator, $this->http, $this->run->siteUrl);
    }

    /**
     * On disk, in the run's own scratch space, because the process that
     * takes the baseline is very often not the process that checks it again:
     * a queue worker starts the run and an admin page polling finishes it. In
     * memory it would simply be absent by then, which reads as "never checked"
     * and quietly disables the whole safety net.
     */
    protected function rememberHealth(string $state): void
    {
        @file_put_contents($this->run->workDir.'/health.before', $state);
    }

    protected function rememberedHealth(): string
    {
        $saved = @file_get_contents($this->run->workDir.'/health.before');

        return is_string($saved) && $saved !== '' ? trim($saved) : 'unchecked';
    }

    /**
     * Never fails the run, and WAITS, which is the whole point of it.
     *
     * By this point the files are updated, the lock is updated, and Composer's
     * record agrees with both. A compiled-code cache that could not be cleared
     * is a thing to TELL somebody about, not a reason to mark a completed update
     * as failed and offer to roll back work that was correct.
     *
     * It used to clear what it could, return a sentence, and let the run finish.
     * On a queue worker it cannot reach the web server's compiled-code cache at
     * all, so the sentence it returned was "PHP will pick them up within 60
     * seconds on its own", and the run went green anyway.
     *
     * That is a completed update that is not in effect. An admin reads
     * "Finished", enables the extension it just installed, and for the rest of
     * that minute every request boots a database that says the extension is on
     * against an autoloader that has never heard of it. Which is not a theory:
     * it took a live forum down, and the log line explaining it was sitting
     * right there in the run the whole time.
     *
     * So the run stays in progress until the web tier can actually see the new
     * files. Finished now means in effect.
     */
    public function codeCache(): string
    {
        $translator = $this->run->translator;

        if ($this->clearWebCache()) {
            return $translator->trans('ernestdefoe-millwright.api.composer.web_cache_cleared');
        }

        $opcache = new Opcache($translator);
        $situation = $opcache->situation();
        $result = $opcache->clear();

        /*
         * Done, or nothing to wait FOR: a host set never to re-read files
         * makes the change live when somebody restarts PHP and not a moment
         * before. Waiting would hang the run forever to no purpose; the honest
         * answer is the one the clear() call already wrote.
         */
        if ($result['done'] || ! $situation['validates']) {
            return $result['why'];
        }

        $left = $this->codeLiveAt(max(1, (int) $situation['freq'])) - time();

        if ($left <= 0) {
            return $translator->trans('ernestdefoe-millwright.api.composer.reread_done');
        }

        /*
         * Bounded. A host with a very long revalidate_freq would otherwise
         * park the admin screen for as long as that window, which is worse
         * than telling the truth and letting them restart PHP.
         */
        if ($left > Opcache::WAIT_CAP) {
            return $translator->trans('ernestdefoe-millwright.api.composer.wait_too_long', ['seconds' => $situation['freq']]);
        }

        throw new NotYet($translator->trans('ernestdefoe-millwright.api.composer.waiting_reread', ['seconds' => $left]));
    }

    /**
     * When the web tier is guaranteed to have re-read the autoloader.
     *
     * Measured from the AUTOLOADER, not from now. It is the file whose
     * staleness actually breaks a site (a class that exists on disk and is
     * unknown to the running process), and it stops changing at the register
     * step, so the phases in between count towards the wait instead of being
     * added to it.
     *
     * The margin is because revalidate_freq counts from when a process last
     * checked a file, not from when the file changed, so the worst case is one
     * full window after the write.
     */
    public function codeLiveAt(int $freq): int
    {
        /*
         * Timed from Composer's install record as well as the autoloader.
         * The autoloader is only rewritten when the set of classes changes, and
         * 2.0.0-rc.8 → 2.0.0 changed none, so its timestamp was days old and the
         * wait was skipped. The site was checked while php-fpm still ran rc.8
         * against a database already at 2.0.0, answered Flarum's 503 "Update
         * Flarum" page, and a correct update was put back (wowcraft,
         * 2026-10-09). InstalledRecord rewrites installed.json on every run.
         */
        clearstatcache();
        $changed = max(
            (int) @filemtime($this->run->installPath.'/vendor/composer/autoload_static.php'),
            (int) @filemtime($this->run->installPath.'/vendor/composer/installed.json'),
        );

        return ($changed ?: time()) + $freq + 2;
    }
}
