<?php

namespace ErnestDefoe\Millwright\Work;

use RuntimeException;
use ErnestDefoe\Millwright\Host\StateFile;
use ErnestDefoe\Millwright\Config\AuthTokens;
use ErnestDefoe\Millwright\Config\JsonFile;
use ErnestDefoe\Millwright\Config\Repositories;
use ErnestDefoe\Millwright\Config\StabilityRule;
use ErnestDefoe\Millwright\Discover\Compatibility;
use ErnestDefoe\Millwright\Host\Web;
use Flarum\Locale\TranslatorInterface;

/**
 * Whether anything newer exists, asked cheaply.
 *
 * This is deliberately NOT the same question as "can I have it".
 *
 * Conflating them makes the answer useless: a newer version existing tells you
 * nothing about whether it will install alongside everything else you have. Only a resolve knows that, and a
 * resolve is 16 seconds and 165 MB — far too expensive to run on a page load, or
 * on a schedule, or once per extension.
 *
 * So there are two answers, and they are kept apart on purpose:
 *
 *   this class — "3.6.0 exists and you are on 3.5.0". One cheap HTTP call per
 *                package, cacheable, safe to run nightly. It is a HINT.
 *   the plan   — "here is exactly what changes, what comes with it, and what
 *                blocks it". Run when somebody actually presses Update.
 *
 * Presenting the hint as a promise would be the lie. The badge says a newer
 * version exists; pressing Update is what finds out whether you can have it.
 */
class UpdateCheck
{
    /**
     * Where each version's release notes live, as read from Packagist while
     * checking: package => version => URL. Filled by readPackagist(), so a
     * check costs no request beyond the one it already makes.
     *
     * @var array<string,array<string,string>>
     */
    protected array $releaseNotes = [];

    /** @var array<string,array<string,string>> package => version => its flarum/core constraint */
    protected array $coreRequires = [];

    /**
     * Saved with each check. A check saved before its updates were held to the
     * installed core counts as stale, so the scheduler replaces it at once.
     */
    public const RULES = 2;

    /** The forum's stability rules; null until forRoot(), and then nothing is filtered. */
    protected ?StabilityRule $stability = null;

    public function __construct(
        protected string $cachePath,
        protected int $freshFor = 21600,        // six hours
    ) {
    }

    /**
     * Offer only what an install could have: a version its stability rules
     * allow (minimum-stability, and a package's own `@beta` flag), and whose
     * flarum/core requirement the installed core meets. Install resolves with
     * exactly these rules, so without them the check offered a version that
     * needs flarum/core ^2.1 to a 2.0 forum on stable, which install had just
     * correctly declined (Claudius, 2026-11-23).
     */
    public function forRoot(StabilityRule $stability): static
    {
        $this->stability = $stability;

        return $this;
    }

    /**
     * The packages a composer.json requires itself: names with a vendor, so
     * not php, ext-* or lib-*.
     *
     * @return list<string>
     */
    public static function directRequires(string $composerJson): array
    {
        $names = array_keys((array) (StateFile::read($composerJson)['require'] ?? []));

        return array_values(array_filter($names, fn ($name) => is_string($name) && str_contains($name, '/')));
    }

    /**
     * Only things the site actually chose.
     *
     * Running this against a real forum returned 59 "updates", of which nearly
     * all were transitive dependencies — illuminate/collections 13.30.0 →
     * 13.30.1, guzzle 7 → 8, doctrine 3 → 4. Nobody updates those individually;
     * they arrive with the extension that needs them, and several are pinned by
     * flarum/core so the newer version is not installable at all.
     *
     * Reporting them would be worse than reporting nothing: a badge showing 59
     * when 4 things matter is a badge people stop reading. So the check is
     * limited to Flarum extensions and Flarum itself — the things somebody
     * deliberately installed and might deliberately update.
     *
     * And to anything the forum's own composer.json requires. fof/redis is
     * a Composer library, not an extension, so it has no settings page and
     * was never reported: its stable release went unseen (Ernest,
     * 2026-10-10). Required directly means somebody chose it; pulled in by
     * another package means nobody did, and those stay quiet.
     *
     * @param list<array{name:string,version:string,type?:string}> $packages
     * @param list<string> $direct packages the root composer.json requires
     * @return array<string,string>
     */
    public function interesting(array $packages, array $direct = []): array
    {
        $direct = array_flip($direct);

        $out = [];

        foreach ($packages as $package) {
            $name = (string) ($package['name'] ?? '');
            $type = (string) ($package['type'] ?? '');

            if ($name === '') {
                continue;
            }

            if ($type === 'flarum-extension' || $name === 'flarum/core' || isset($direct[$name])) {
                $out[$name] = (string) ($package['version'] ?? '');
            }
        }

        return $out;
    }

    /**
     * A package tracking a branch is not "uncheckable" — it is not a
     * version question at all.
     *
     * `dev-main` has no version to be newer than. Whether the branch has
     * moved is something only a resolve can answer, and this command
     * deliberately does not resolve. Lumping these in with packages we
     * genuinely could not reach told an admin that four extensions
     * "could not be checked", which is two wrong things at once: they
     * were reachable, and nothing was wrong.
     *
     * It also saves the request, which is the point of a nightly check
     * staying cheap.
     *
     * Named, not silently dropped. A protected or path-installed
     * package is not on Packagist, and "we could not check this one"
     * is a true and useful thing to say — whereas leaving it out
     * implies it is up to date, which is a guess presented as a fact.
     *
     * The directory is made here, not assumed. Nothing else creates
     * storage/millwright until the first update run, so on a fresh install
     * every check — the nightly one, the console one and "Check now" — wrote
     * into a directory that did not exist, the @ swallowed it, and the
     * screen said "Not checked yet" with no Update button anywhere, over a
     * check that had just reported eleven newer versions.
     *
     * @param array<string,string> $installed package => installed version
     * @param callable(string):?array $fetch  a package's published versions,
     *                                        null when it could not be asked
     * @param callable(string):array<string,string>|null $notesFor release links
     *        for packages Packagist does not know, version => URL
     * @return array<string,mixed>
     */
    public function refresh(array $installed, callable $fetch, ?callable $notesFor = null): array
    {
        $found = [];
        $unknown = [];
        $tracking = [];

        foreach ($installed as $name => $version) {
            if (self::tracksABranch($version)) {
                $tracking[] = $name;
                continue;
            }

            $versions = $fetch($name);

            if ($versions === null) {
                $unknown[] = $name;
                continue;
            }

            $newest = $this->newestComparable($versions, $version, $name, $installed['flarum/core'] ?? null);

            if ($newest !== null && $this->isNewer($newest, $version)) {
                $found[$name] = $this->offer($name, $version, $newest, $notesFor);
            }
        }

        $result = [
            'checkedAt' => time(),
            'rules' => self::RULES,
            'updates' => $found,
            'uncheckable' => $unknown,
            'tracking' => $tracking,
        ];

        if (! StateFile::write($this->cachePath, $result)) {
            $result['unsaved'] = true;
        }

        return $result;
    }

    /**
     * One update as the check saves it. Its core requirement is kept so a saved
     * check can be held against the core installed when it is read. Its
     * release notes answer "what does this update give me?" (ClaudiusH,
     * 2026-10-09): a link to the release, never a guess, absent when the
     * package is not on GitHub.
     *
     * @param callable(string):array<string,string>|null $notesFor
     * @return array<string,string>
     */
    protected function offer(string $name, string $from, string $to, ?callable $notesFor): array
    {
        $update = ['from' => $from, 'to' => $to];

        if (isset($this->coreRequires[$name][$to])) {
            $update['core'] = $this->coreRequires[$name][$to];
        }

        $notes = $this->releaseNotes[$name][$to] ?? ($notesFor ? ($notesFor($name)[$to] ?? null) : null);

        if ($notes !== null) {
            $update['notes'] = $notes;
        }

        return $update;
    }

    /**
     * Packagist first, then the site's own protected repositories: the check the
     * console command and "Check now" both run.
     *
     * One place, because they drifted. "Check now" asked Packagist alone,
     * so pressing it dropped every protected extension's update that the nightly
     * check had found, until the next night put them back.
     *
     * @param array<string,string> $installed
     * @return array<string,mixed>
     */
    public function refreshWith(array $installed, PrivateIndex $protected, Web $web, float $seconds): array
    {
        $deadline = microtime(true) + $seconds;
        $asked = array_filter($installed, fn (string $version) => ! self::tracksABranch($version));

        // Packagist for every package at once, within the time limit.
        $bodies = $web->getMany(array_combine(
            array_keys($asked),
            array_map(fn (string $name) => 'https://repo.packagist.org/p2/'.$name.'.json', array_keys($asked))
        ) ?: [], $seconds);

        // Protected repositories one by one, each request shortened to fit:
        // a slow GitHub then costs those packages a "could not check", not the request.
        $protected->until($deadline);

        return $this->refresh(
            $installed,
            function (string $name) use ($protected, $bodies) {
                $versions = $this->readPackagist($name, $bodies[$name] ?? null);

                if ($versions !== null) {
                    return $versions;
                }

                $versions = $protected->versionsFor($name);
                $this->coreRequires[$name] = $protected->coreRequirementsFor($name);

                return $versions;
            },
            fn (string $name) => $protected->releaseNotesFor($name),
        );
    }

    /**
     * The whole check for a forum: what its lock says is installed, filtered
     * as {@see interesting()}, asked of Packagist and then its protected
     * repositories. Null when composer.lock cannot be read.
     *
     * One place for "Check now", millwright:check and the re-check
     * millwright:update makes before giving up on a pin.
     *
     * $seconds bounds the whole check: "Check now" runs inside a web request.
     *
     * @return array<string,mixed>|null
     */
    public function refreshSite(TranslatorInterface $translator, string $base, Web $web, float $seconds): ?array
    {
        $lock = StateFile::read($base.'/composer.lock');
        $packages = array_merge(array_values((array) ($lock['packages'] ?? [])), array_values((array) ($lock['packages-dev'] ?? [])));

        if ($packages === []) {
            return null;
        }

        $result = $this->forRoot(StabilityRule::fromComposerJson($base.'/composer.json'))->refreshWith($this->interesting($packages, self::directRequires($base.'/composer.json')), new PrivateIndex(
            new Repositories($translator, new JsonFile($translator, $base.'/composer.json')),
            new AuthTokens($translator, new JsonFile($translator, $base.'/auth.json')),
            $web,
        ), $web, $seconds);

        // A check nobody can read back would leave the screen at "Not checked
        // yet" over a check that had just found updates.
        if (! empty($result['unsaved'])) {
            throw new RuntimeException($translator->trans('ernestdefoe-millwright.api.fs.write_failed', ['path' => $this->cachePath]));
        }

        return $result;
    }

    /** @return array<string,mixed> */
    public function cached(): array
    {
        if (! is_file($this->cachePath)) {
            return ['checkedAt' => null, 'updates' => [], 'uncheckable' => [], 'tracking' => []];
        }

        return StateFile::read($this->cachePath);
    }

    /**
     * The cached check, minus what has been installed since.
     *
     * The check is saved when it runs and an update does not re-run it, so
     * straight after updating Mobile Tab to 2.0.1 its card still offered
     * "2.0.0 → 2.0.1" (ClaudiusH, 2026-10-07), and Millwright offered itself the
     * version it was already running. Each entry is measured against what
     * composer.lock says now: dropped once reached, and its "from" kept true
     * when only part of the way. Dropped too when the forum's stability rules
     * or its installed core no longer allow the target.
     *
     * @return array<string,mixed>
     */
    public function current(string $lockPath): array
    {
        return $this->currentFor(StateFile::read($lockPath), StateFile::read(dirname($lockPath).'/composer.json'));
    }

    /**
     * The same, for a caller that has already read the forum's lock and
     * composer.json and need not read them twice.
     *
     * @param array<string,mixed> $lock
     * @param array<string,mixed> $composerJson
     * @return array<string,mixed>
     */
    public function currentFor(array $lock, array $composerJson): array
    {
        $cached = $this->cached();
        $now = $this->interesting(
            array_merge((array) ($lock['packages'] ?? []), (array) ($lock['packages-dev'] ?? [])),
            array_values(array_filter(array_keys((array) ($composerJson['require'] ?? [])), fn ($name) => is_string($name) && str_contains($name, '/')))
        );

        $rule = $composerJson === [] ? null : StabilityRule::fromArray($composerJson);
        $updates = [];

        foreach ((array) ($cached['updates'] ?? []) as $name => $update) {
            $installed = $now[$name] ?? null;

            // Saved before the rules held it, or under another core: Update could not deliver it.
            if (($rule !== null && ! $rule->allows($name, (string) ($update['to'] ?? '')))
                || ! self::coreRuns($now['flarum/core'] ?? null, is_string($update['core'] ?? null) ? $update['core'] : null)) {
                continue;
            }

            if ($installed === null || self::tracksABranch($installed)) {
                $updates[$name] = $update;
            } elseif ($this->isNewer((string) ($update['to'] ?? ''), $installed)) {
                $updates[$name] = ['from' => $installed] + $update;
            }
        }

        return ['updates' => $updates] + $cached;
    }

    public function isStale(): bool
    {
        $cached = $this->cached();
        $at = $cached['checkedAt'] ?? null;

        return $at === null || (int) ($cached['rules'] ?? 1) < self::RULES || (time() - (int) $at) > $this->freshFor;
    }

    /** A branch install: `dev-main`, `1.x-dev`. */
    public static function tracksABranch(string $version): bool
    {
        return str_starts_with($version, 'dev-') || str_contains($version, '-dev');
    }

    /**
     * The newest version worth comparing against what is installed.
     *
     * A site on a dev branch is not offered a tagged release, and a site on a
     * stable tag is not offered a dev branch. Mixing them produces "update
     * available: dev-main" on a forum deliberately pinned to 2.0.0, which is
     * noise that trains people to ignore the badge.
     *
     * @param list<string> $versions
     */
    protected function newestComparable(array $versions, string $installed, string $name = '', ?string $core = null): ?string
    {
        $installedIsDev = self::tracksABranch($installed);

        $candidates = array_values(array_filter($versions, function (string $v) use ($installedIsDev, $name, $core) {
            return self::tracksABranch($v) === $installedIsDev && $this->installable($name, $v, $core);
        }));

        if ($candidates === []) {
            return null;
        }

        // Under prefer-stable, Composer picks the most stable release there is.
        if ($this->stability?->prefersStable()) {
            $best = min(array_map(StabilityRule::rank(...), $candidates));
            $candidates = array_values(array_filter($candidates, fn (string $v) => StabilityRule::rank($v) === $best));
        }

        usort($candidates, fn (string $a, string $b) => version_compare(
            $this->normalise($a),
            $this->normalise($b)
        ));

        return end($candidates) ?: null;
    }

    /**
     * Whether install could pick this version, by the rules forRoot() read.
     * A version whose core requirement is unknown (a GitHub tag) is judged on
     * stability alone.
     */
    protected function installable(string $name, string $version, ?string $core): bool
    {
        if ($this->stability === null) {
            return true;
        }

        if (! $this->stability->allows($name, $version)) {
            return false;
        }

        return self::coreRuns($core, $this->coreRequires[$name][$version] ?? null);
    }

    /**
     * No constraint (a GitHub tag carries none) counts as yes, and so does a
     * core on a named branch, which has no version to compare. A numbered
     * branch such as 2.x-dev is compared, as Composer does, and a constraint
     * Composer cannot read is a no, as it is for install.
     */
    public static function coreRuns(?string $installedCore, ?string $constraint): bool
    {
        if ($constraint === null || $installedCore === null || $installedCore === '' || str_starts_with($installedCore, 'dev-')) {
            return true;
        }

        return (new Compatibility(ltrim($installedCore, 'v')))->admitsCore($constraint);
    }

    protected function isNewer(string $candidate, string $installed): bool
    {
        // Two dev branches of the same name are never "newer" than each other by
        // version string — whether a branch has moved is a question for the
        // resolve, not for this.
        if (str_starts_with($candidate, 'dev-')) {
            return false;
        }

        return version_compare($this->normalise($candidate), $this->normalise($installed)) > 0;
    }

    protected function normalise(string $version): string
    {
        return ltrim($version, 'v');
    }

    /**
     * A package's versions from its Packagist p2 file, null when there was no
     * answer or the package is not on Packagist. Notes the release pages seen
     * on the way, so a check costs no request beyond the one it already makes.
     *
     * @return list<string>|null
     */
    protected function readPackagist(string $name, ?string $body): ?array
    {
        $versions = $body === null ? null : (json_decode($body, true)['packages'][$name] ?? null);

        if (! is_array($versions)) {
            return null;
        }

        return $this->remember($name, $versions);
    }

    /**
     * A package's Packagist entries read and kept: its release links and each
     * version's core requirement, for the check that follows.
     *
     * @param list<array<string,mixed>> $entries
     * @return list<string> the versions
     */
    public function remember(string $name, array $entries): array
    {
        [$list, $notes, $core] = self::readP2($entries);
        $this->releaseNotes[$name] = $notes;
        $this->coreRequires[$name] = $core;

        return $list;
    }

    /**
     * The versions in a Packagist p2 response, and each one's GitHub release.
     *
     * p2 is MINIFIED: each entry repeats only what changed since the one
     * before it, and `__unset` removes a key. `source` is usually given once, on
     * the first entry, so reading each entry on its own found a repository for
     * the newest version and nothing for the rest. Expanded by
     * Compatibility::expand(), as Composer's MetadataMinifier does.
     *
     * The tag is the version as written (`v1.2.0` or `1.2.0`), which is the
     * name Packagist read from the repository.
     *
     * @param list<array<string,mixed>> $entries
     * @return array{0: list<string>, 1: array<string,string>, 2: array<string,string>} versions, release
     *         links, and each version's flarum/core requirement
     */
    public static function readP2(array $entries): array
    {
        $versions = [];
        $notes = [];
        $core = [];

        foreach (Compatibility::expand(array_values(array_filter($entries, 'is_array')), 'composer/2.0') as $entry) {
            if (! isset($entry['version'])) {
                continue;
            }

            $version = (string) $entry['version'];
            $versions[] = $version;

            if (is_string($needs = $entry['require']['flarum/core'] ?? null)) {
                $core[$version] = $needs === 'self.version' ? $version : $needs;
            }

            $url = self::releaseUrl((string) ($entry['source']['url'] ?? ''), $version);

            if ($url !== null) {
                $notes[$version] = $url;
            }
        }

        return [$versions, $notes, $core];
    }

    /**
     * A tagged version's release page on GitHub, or null for anything else: a
     * branch has no release, and other hosts lay their pages out differently.
     */
    public static function releaseUrl(string $sourceUrl, string $version): ?string
    {
        if (self::tracksABranch($version)
            || ! preg_match('#^(?:https?://|git@)github\.com[/:]([\w.-]+)/([\w.-]+?)(?:\.git)?/?$#', $sourceUrl, $m)) {
            return null;
        }

        return 'https://github.com/'.$m[1].'/'.$m[2].'/releases/tag/'.rawurlencode($version);
    }
}
