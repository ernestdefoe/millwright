<?php

namespace ErnestDefoe\Millwright\Work;

use RuntimeException;

/**
 * Runs Composer, out of process where the host allows it.
 *
 * 🚨 A subprocess by preference, and the reason is the whole story of why the
 * current tooling fails. Extension Manager runs Composer *inside* the PHP worker
 * serving the request, which is what makes its memory cap load-bearing, what
 * makes a killed request able to take the vendor directory with it, and what
 * puts a booted Flarum's memory on the same budget as the resolve.
 *
 * Out of process, none of that is true: Composer gets its own limit, its own
 * lifetime, and its failures are exit codes rather than a dead worker.
 *
 * Where proc_open is unavailable — a great many shared hosts — this falls back
 * to running in-process, which still works. It is not a lesser design so much as
 * a smaller safety margin, and the host panel says which one you are on.
 */
class ComposerRunner
{
    public function __construct(
        private string $installPath,
        private ?string $composerBin = null,
        private ?string $composerHome = null,
    ) {
    }

    public function canSpawn(): bool
    {
        if (! function_exists('proc_open')) {
            return false;
        }

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return ! in_array('proc_open', $disabled, true) && $this->binary() !== null;
    }

    /**
     * @param list<string> $args
     * @return array{code:int, output:string}
     */
    public function run(array $args, int $timeout = 600): array
    {
        if (! $this->canSpawn()) {
            throw new RuntimeException(
                'Composer cannot be run as a separate process on this host, and the in-process path is not wired up yet.'
            );
        }

        $cmd = array_merge(
            [PHP_BINARY, $this->binary()],
            $args,
            ['--no-interaction', '--working-dir=' . $this->installPath]
        );

        $env = [
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            /*
             * 🚨 -1, set explicitly. Composer raises its own limit to 1.5G on
             * startup unless this is set, which would make any measurement of
             * what a host can actually do meaningless — and the whole capability
             * panel depends on those measurements being real.
             */
            'COMPOSER_MEMORY_LIMIT' => '-1',
            'COMPOSER_HOME' => $this->composerHome ?? ($this->installPath . '/storage/.composer'),
            'HOME' => getenv('HOME') ?: $this->installPath,
        ];

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open($cmd, $descriptors, $pipes, $this->installPath, $env);

        if (! is_resource($process)) {
            throw new RuntimeException('Could not start Composer.');
        }

        // stderr folded into the output on purpose: Composer says most of what
        // matters there, and a user reading a failure wants all of it in order.
        $output = '';
        $deadline = time() + $timeout;

        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }

        while (true) {
            $status = proc_get_status($process);
            $output .= (string) stream_get_contents($pipes[1]);
            $output .= (string) stream_get_contents($pipes[2]);

            if (! $status['running']) {
                break;
            }

            if (time() > $deadline) {
                proc_terminate($process, 9);
                throw new RuntimeException("Composer did not finish within {$timeout}s.");
            }

            usleep(50000);
        }

        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        return ['code' => proc_close($process), 'output' => trim($output)];
    }

    private function binary(): ?string
    {
        if ($this->composerBin !== null) {
            return $this->composerBin;
        }

        foreach (['/usr/local/bin/composer', '/usr/bin/composer', $this->installPath . '/composer.phar'] as $candidate) {
            if (is_file($candidate)) {
                return $this->composerBin = $candidate;
            }
        }

        return null;
    }
}
