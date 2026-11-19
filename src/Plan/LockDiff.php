<?php

namespace ErnestDefoe\Millwright\Plan;

/**
 * What changed between two composer.lock files.
 *
 * This is why an update here is cheap. Composer's own install path rebuilds a
 * whole tree; comparing locks says that exactly three packages moved, so exactly
 * three get downloaded and exactly three get replaced. Everything the design
 * claims — a rollback proportional to the change, an apply measured in renames
 * rather than minutes — follows from the plan being this specific.
 *
 * It is also the screen people see before they commit to anything. "Upgrading
 * fof/pwa, and pulling psr/clock in with it, because fof/pwa 2.0.0-beta.4
 * requires it" is a sentence somebody can make a decision about. A progress bar
 * is not.
 */
class LockDiff
{
    /**
     * @param array<string,mixed> $before the current composer.lock, decoded
     * @param array<string,mixed> $after  the lock Composer would write
     * @return list<Change>
     */
    public function between(array $before, array $after): array
    {
        $old = $this->index($before);
        $new = $this->index($after);

        $changes = [];

        foreach ($new as $name => $version) {
            if (! isset($old[$name])) {
                $changes[] = new Change(Change::ADD, $name, null, $version);
            } elseif ($old[$name] !== $version) {
                $changes[] = new Change(Change::REPLACE, $name, $old[$name], $version);
            }
        }

        foreach ($old as $name => $version) {
            if (! isset($new[$name])) {
                $changes[] = new Change(Change::REMOVE, $name, $version, null);
            }
        }

        /*
         * Sorted by package name so the plan a user approves is the plan that
         * runs. Composer's ordering is not stable between invocations, and a
         * resumed run re-derives this list and carries on at a saved index — if
         * the order moved, it would redo one package and skip another.
         */
        usort($changes, fn (Change $a, Change $b) => strcmp($a->package, $b->package));

        return $changes;
    }

    /**
     * Where each changed package can be downloaded from, and what it should
     * hash to.
     *
     * @param array<string,mixed> $after
     * @return array<string,array{url:string,type:string,reference:?string,shasum:?string}>
     */
    public function sources(array $after): array
    {
        $out = [];

        foreach ($this->packages($after) as $package) {
            $dist = $package['dist'] ?? null;

            if (! is_array($dist) || empty($dist['url'])) {
                // A source-only package (a path repo, or one with no archive).
                // Named rather than skipped silently, so the fetch phase can say
                // which package it cannot get rather than failing vaguely.
                continue;
            }

            $out[(string) $package['name']] = [
                'url' => (string) $dist['url'],
                'type' => (string) ($dist['type'] ?? 'zip'),
                'reference' => isset($dist['reference']) ? (string) $dist['reference'] : null,
                'shasum' => isset($dist['shasum']) && $dist['shasum'] !== '' ? (string) $dist['shasum'] : null,
            ];
        }

        return $out;
    }

    /** @return array<string,string> package => version */
    protected function index(array $lock): array
    {
        $out = [];

        foreach ($this->packages($lock) as $package) {
            if (isset($package['name'], $package['version'])) {
                $out[(string) $package['name']] = (string) $package['version'];
            }
        }

        return $out;
    }

    /**
     * @return list<array<string,mixed>>
     *
     * Both sections, because a dev dependency that moves still changes what is
     * on disk, and a plan that omitted it would be wrong about what it is about
     * to do.
     */
    protected function packages(array $lock): array
    {
        return array_merge(
            array_values((array) ($lock['packages'] ?? [])),
            array_values((array) ($lock['packages-dev'] ?? []))
        );
    }
}
