<?php

namespace ErnestDefoe\Millwright\Host;

use Flarum\Locale\TranslatorInterface;
use RuntimeException;

/**
 * Millwright's own state under storage/: written beside the file and renamed
 * into place, so a kill or a full disk never leaves half a file where a whole
 * one was.
 *
 * Plain files on purpose, not a Flarum filesystem disk: the rename is what
 * makes each write atomic, and a run's files must be readable by whichever
 * process picks the run up next (a web request, a queue worker, the command
 * line), before Flarum has booted in some of them.
 */
class StateFile
{
    /** True when $path now holds exactly $data. */
    public static function write(string $path, mixed $data): bool
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $dir = dirname($path);

        if ($json === false || (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir))) {
            return false;
        }

        $tmp = $path.'.'.bin2hex(random_bytes(4)).'.tmp';

        if (@file_put_contents($tmp, $json) !== strlen($json) || ! @rename($tmp, $path)) {
            @unlink($tmp);

            return false;
        }

        return true;
    }

    /** The same, for state a run cannot go on without: a failure stops it, saying where. */
    public static function writeOrFail(string $path, mixed $data, TranslatorInterface $translator): void
    {
        if (! self::write($path, $data)) {
            throw new RuntimeException($translator->trans('ernestdefoe-millwright.api.fs.write_failed', ['path' => $path]));
        }
    }
}
