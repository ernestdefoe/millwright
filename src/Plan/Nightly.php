<?php

namespace ErnestDefoe\Millwright\Plan;

use ErnestDefoe\Millwright\Host\StateFile;
use ErnestDefoe\Millwright\Host\Web;

/**
 * Moving Flarum to its nightly build: which requirements change, and to what.
 *
 * The update check never offers a branch to a forum on a tagged release —
 * rightly, as a badge — so a nightly cannot come from it. And the forum's own
 * composer.json cannot reach one either: `flarum/core: ^2.0.0-rc.8` with the
 * bundled extensions at `*` under `minimum-stability: beta` and
 * `prefer-stable` will never resolve to `2.x-dev`. Each requirement has to be
 * raised to the branch explicitly, which is also what grants that one package
 * dev stability.
 *
 * Core and the bundled extensions are split from one monorepo and move
 * together: core alone on nightly beside rc extensions is a combination
 * nobody tested. So every `flarum/*` package this forum REQUIRES that
 * publishes `{major}.x-dev` is moved, and nothing else. The branch name is
 * worked out here, on the server, from the installed core and Packagist —
 * never taken from a request.
 *
 * Undo is unchanged: composer.json is saved before the requirements are
 * raised, and putting it back returns the forum to the release it was on.
 */
class Nightly
{
    public function __construct(
        protected string $composerJsonPath,
        protected string $lockPath,
        protected Web $web,
    ) {
    }

    /** The branch this forum's Flarum would track, e.g. `2.x-dev`, or null if core cannot be read. */
    public function branch(): ?string
    {
        $lock = StateFile::read($this->lockPath);

        foreach ((array) ($lock['packages'] ?? []) as $package) {
            if (($package['name'] ?? '') === 'flarum/core' && preg_match('/^v?(\d+)\./', (string) ($package['version'] ?? ''), $m)) {
                return $m[1].'.x-dev';
            }
        }

        return null;
    }

    /** @return array<string,string> package => the branch it would be required at */
    public function targets(): array
    {
        $branch = $this->branch();
        $require = (array) (StateFile::read($this->composerJsonPath)['require'] ?? []);

        if ($branch === null || ! isset($require['flarum/core'])) {
            return [];
        }

        $names = array_values(array_filter(array_map('strval', array_keys($require)), fn (string $name) => str_starts_with($name, 'flarum/')));
        $out = [];

        // Every flarum/* package at once: this runs in the request that starts the run.
        foreach ($this->devVersions($names) as $name => $versions) {
            if (is_array($versions) && in_array($branch, $versions, true)) {
                $out[$name] = $branch;
            }
        }

        // Core decides: without its branch there is no nightly to move to.
        return isset($out['flarum/core']) ? $out : [];
    }

    /**
     * Each package's branch versions on Packagist, null when unreachable.
     *
     * @param list<string> $names
     * @return array<string,list<string>|null>
     */
    protected function devVersions(array $names): array
    {
        $bodies = $this->web->getMany(array_combine($names, array_map(fn (string $name) => 'https://repo.packagist.org/p2/'.$name.'~dev.json', $names)) ?: [], 20.0, 10);
        $out = [];

        foreach ($names as $name) {
            $data = isset($bodies[$name]) ? json_decode($bodies[$name], true) : null;
            $out[$name] = is_array($data)
                ? array_values(array_map(fn ($v) => (string) ($v['version'] ?? ''), (array) ($data['packages'][$name] ?? [])))
                : null;
        }

        return $out;
    }
}
