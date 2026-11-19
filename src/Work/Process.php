<?php

namespace ErnestDefoe\Millwright\Work;

use Flarum\Locale\TranslatorInterface;
use RuntimeException;

/**
 * Runs one command and waits for it, without a shell.
 *
 * An ARRAY command, never a string. proc_open with an array bypasses the
 * shell entirely, so a path containing a space is just a path — no quoting to
 * get right, and no way for anything to be re-parsed as a command.
 *
 * The version this replaced built a string with escapeshellcmd(), which escapes
 * shell metacharacters and does NOT quote spaces. On a machine whose PHP lives
 * under "Application Support" the command split at the space, and the error was
 * `sh: /Users/x/Library/Application: No such file or directory` — which names
 * neither the command that failed nor the reason.
 *
 * Both pipes are drained as it runs. Reading stdout to completion before
 * touching stderr deadlocks the moment a command writes more to stderr than the
 * pipe buffer holds — rare enough to pass every test and then hang on somebody
 * else's host.
 */
class Process
{
    /**
     * @param list<string> $command
     * @param array<string,string> $env
     * @return array{code:int, output:string}
     */
    public static function run(TranslatorInterface $translator, array $command, string $cwd, array $env = [], int $timeout = 600): array
    {
        // stderr folded into the output on purpose: most tools say what matters
        // there, and somebody reading a failure wants all of it, in order.
        $result = self::drain($command, $cwd, $env, $timeout, true);

        if ($result['code'] === self::NOT_STARTED) {
            throw new RuntimeException($translator->trans('ernestdefoe-millwright.api.process.start_failed', ['command' => $command[0] ?? $translator->trans('ernestdefoe-millwright.api.process.the_command')]));
        }

        if ($result['code'] === self::TIMED_OUT) {
            throw new RuntimeException($translator->trans('ernestdefoe-millwright.api.process.timeout', ['command' => $command[0] ?? $translator->trans('ernestdefoe-millwright.api.process.the_command_start'), 'seconds' => (string) $timeout]));
        }

        return ['code' => $result['code'], 'output' => trim($result['out'])];
    }

    /**
     * The same, keeping stdout and stderr apart and never throwing: a probe
     * that cannot start, or hangs, is simply a binary that does not work.
     *
     * @param list<string> $command
     * @return array{code:int, out:string, err:string} code -1 when it could not start or ran out of time
     */
    public static function capture(array $command, int $timeout = 10): array
    {
        $result = self::drain($command, null, [], $timeout, false);

        return in_array($result['code'], [self::NOT_STARTED, self::TIMED_OUT], true) ? ['code' => -1] + $result : $result;
    }

    /** Out of range for an exit code, so neither can be mistaken for one. */
    protected const NOT_STARTED = PHP_INT_MIN;

    protected const TIMED_OUT = PHP_INT_MIN + 1;

    /**
     * @param list<string> $command
     * @param array<string,string> $env
     * @return array{code:int, out:string, err:string}
     */
    protected static function drain(array $command, ?string $cwd, array $env, int $timeout, bool $merge): array
    {
        $process = @proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $env ?: null);

        if (! is_resource($process)) {
            return ['code' => self::NOT_STARTED, 'out' => '', 'err' => ''];
        }

        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }

        $out = '';
        $err = '';
        $deadline = time() + $timeout;

        while (true) {
            $status = proc_get_status($process);
            $out .= (string) stream_get_contents($pipes[1]);
            $merge ? $out .= (string) stream_get_contents($pipes[2]) : $err .= (string) stream_get_contents($pipes[2]);

            if (! $status['running']) {
                break;
            }

            if (time() > $deadline) {
                proc_terminate($process, 9);

                return ['code' => self::TIMED_OUT, 'out' => $out, 'err' => $err];
            }

            usleep(50000);
        }

        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        return ['code' => proc_close($process), 'out' => $out, 'err' => $err];
    }
}
