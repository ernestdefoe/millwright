<?php

namespace ErnestDefoe\Millwright\Run;

use ErnestDefoe\Millwright\Host\StateFile;
use Flarum\Locale\TranslatorInterface;
use RuntimeException;

/**
 * Where a run's state lives between requests.
 *
 * Written atomically — a temp file plus a rename — because this is read by
 * one request while another is writing it. Every poll of the admin screen reads
 * this file, and every step writes it; a torn read would show a run that never
 * existed, and on a busy forum that would be common rather than rare.
 *
 * A file rather than a database row, deliberately. The whole design goal is that
 * progress survives a killed request on a host that gives no guarantees, and a
 * file with an fsync is the least machinery between an intention and the disk.
 * It also means the driver can be exercised with no Flarum and no database,
 * which is why phases 2 and 3 have real tests at all.
 */
class RunStore
{
    public function __construct(
        protected TranslatorInterface $translator,
        protected string $dir,
    ) {
    }

    public function save(Run $run): void
    {
        $this->ensureDir();

        $path = $this->path($run->id);
        $temp = $path.'.'.bin2hex(random_bytes(4)).'.tmp';

        $json = json_encode($run->toArray(), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        $handle = @fopen($temp, 'wb');

        if ($handle === false) {
            throw new RuntimeException($this->translator->trans('ernestdefoe-millwright.api.run.state_write_failed', ['path' => $temp]));
        }

        // A short write (a full disk) must not be renamed over the last good state.
        if (fwrite($handle, (string) $json) !== strlen((string) $json)) {
            fclose($handle);
            @unlink($temp);

            throw new RuntimeException($this->translator->trans('ernestdefoe-millwright.api.run.state_write_failed', ['path' => $temp]));
        }

        fflush($handle);
        fsync($handle);
        fclose($handle);

        // rename() is atomic, so a concurrent reader sees either the whole
        // previous state or the whole new one, and never a half-written file.
        if (! @rename($temp, $path)) {
            @unlink($temp);

            throw new RuntimeException($this->translator->trans('ernestdefoe-millwright.api.run.state_move_failed', ['path' => $path]));
        }

        $this->pointAt($run);

        // Every run that reaches an end is in the history, from this one place.
        (new History(dirname(rtrim($this->dir, '/'))))->record($run);
    }

    /**
     * Which run is the latest, kept beside the records so the admin page's
     * poll reads one record rather than every run the forum has ever had.
     * Named without `.json` so nothing that lists the records counts it.
     */
    protected function pointAt(Run $run, bool $force = false): void
    {
        $pointer = rtrim($this->dir, '/').'/latest';
        $current = StateFile::read($pointer);

        if ($force || (int) ($current['startedAt'] ?? -1) <= $run->startedAt) {
            // Best effort: without it, latest() falls back to reading them all.
            @file_put_contents($pointer.'.tmp', json_encode(['id' => $run->id, 'startedAt' => $run->startedAt]));
            @rename($pointer.'.tmp', $pointer);
        }
    }

    /** @return list<array<string,mixed>> what changed recently, newest first */
    public function history(): array
    {
        return (new History(dirname(rtrim($this->dir, '/'))))->all();
    }

    public function load(string $id): ?Run
    {
        $path = $this->path($id);

        if (! is_file($path)) {
            return null;
        }

        $row = json_decode((string) file_get_contents($path), true);

        return is_array($row) ? Run::fromArray($row) : null;
    }

    /** The most recently started run, which is what the admin screen opens on. */
    public function latest(): ?Run
    {
        $pointer = StateFile::read(rtrim($this->dir, '/').'/latest');

        if (is_string($pointer['id'] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $pointer['id']) && ($run = $this->load($pointer['id'])) !== null) {
            return $run;
        }

        // No pointer yet (records written before it existed), or it names a
        // run whose record has gone: read them all, once, and point again.
        $runs = $this->all();

        if ($runs !== []) {
            $this->pointAt($runs[0], true);
        }

        return $runs[0] ?? null;
    }

    /** @return list<Run> newest first */
    public function all(): array
    {
        if (! is_dir($this->dir)) {
            return [];
        }

        $runs = [];

        foreach (glob($this->dir.'/*.json') ?: [] as $file) {
            $row = json_decode((string) file_get_contents($file), true);

            if (is_array($row) && isset($row['id'])) {
                $runs[] = Run::fromArray($row);
            }
        }

        usort($runs, fn (Run $a, Run $b) => $b->startedAt <=> $a->startedAt);

        return $runs;
    }

    /**
     * The run as the admin page sees it: its own state, plus what it was asked
     * to do, so the screen can say WHICH extension is being updated rather
     * than only how far along it is. Read from the run's work directory, which
     * sits beside its record.
     *
     * @return array<string,mixed>|null
     */
    public function present(?Run $run): ?array
    {
        if ($run === null) {
            return null;
        }

        $this->path($run->id);   // validates the id before it reaches a path
        $manifest = StateFile::read(rtrim($this->dir, '/').'/'.$run->id.'/requested.json');

        $migrations = StateFile::read(rtrim($this->dir, '/').'/'.$run->id.'/migrations.json');

        return $run->toArray() + [
            'packages' => array_values(array_filter((array) ($manifest['packages'] ?? []), 'is_string')),
            'mode' => (string) ($manifest['mode'] ?? 'update'),
            // The database changes undoing would reverse; see MigrationLedger.
            'migrations' => array_values(array_map(fn ($m) => (string) ($m['migration'] ?? ''), (array) $migrations)),
        ];
    }

    protected function path(string $id): string
    {
        // Ids are generated, never taken from a request — but this is the
        // second place a name reaches the filesystem, so it is checked here too
        // rather than trusted because of where it came from.
        if (! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) {
            throw new RuntimeException("Refusing to build a path from run id: $id");
        }

        return rtrim($this->dir, '/').'/'.$id.'.json';
    }

    protected function ensureDir(): void
    {
        if (! is_dir($this->dir) && ! mkdir($this->dir, 0775, true) && ! is_dir($this->dir)) {
            throw new RuntimeException($this->translator->trans('ernestdefoe-millwright.api.run.dir_failed', ['path' => $this->dir]));
        }
    }
}
