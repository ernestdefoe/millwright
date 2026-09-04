<?php

namespace ErnestDefoe\Millwright\Host;

/**
 * Where the command-line PHP is.
 *
 * 🚨 PHP_BINARY is the wrong answer under FPM, and FPM is most hosts.
 *
 * Under php-fpm, PHP_BINARY is the FPM binary — typically /usr/sbin/php-fpm —
 * and handing it a script does not run the script. The failure is confusing in
 * exactly the way that costs an afternoon: proc_open succeeds, a process starts,
 * and it exits having done nothing recognisable. Only under the CLI SAPI is
 * PHP_BINARY certainly a PHP that runs scripts.
 *
 * PHP_BINDIR is tried first, because that is the installation THIS request is
 * running under: same version, same extensions, same php.ini directory. That
 * matters when the subprocess is about to resolve dependencies against `php`
 * platform constraints, or boot Flarum.
 *
 * Its own class because two callers need it and they used to disagree — Composer
 * was spawned one way and `flarum migrate` another, so an update could get
 * through the expensive phases and fall over on the last one.
 */
class PhpBinary
{
    private ?string $resolved = null;

    public function __construct(private ?string $override = null)
    {
    }

    public function path(): ?string
    {
        if ($this->override !== null) {
            return $this->override;
        }

        if ($this->resolved !== null) {
            return $this->resolved;
        }

        if (PHP_SAPI === 'cli' && is_file(PHP_BINARY)) {
            return $this->resolved = PHP_BINARY;
        }

        foreach ($this->candidates() as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $this->resolved = $candidate;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function candidates(): array
    {
        return array_values(array_filter([
            PHP_BINDIR . '/php',
            // Some panels install per-version binaries side by side and leave
            // the bare name pointing at whatever the host defaults to, which may
            // not be the version this forum runs on.
            PHP_BINDIR . '/php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
            PHP_BINDIR . '/php' . PHP_MAJOR_VERSION . PHP_MINOR_VERSION,
            '/usr/local/bin/php',
            '/usr/bin/php',
        ]));
    }
}
