<?php

namespace ErnestDefoe\Millwright\Work;

use ErnestDefoe\Millwright\Apply\Journal;
use ErnestDefoe\Millwright\Apply\Restore;
use ErnestDefoe\Millwright\Host\Opcache;
use ErnestDefoe\Millwright\Host\StorageDir;
use ErnestDefoe\Millwright\Plan\Change;
use Flarum\Locale\TranslatorInterface;

/**
 * One run's facts, shared by its phases: where the forum is, the run's own
 * scratch space, what was asked for, and the plan written down after the
 * resolve.
 *
 * Every phase class reads from here and none of them keeps state of its own
 * between items. That is what lets a driver holding only a run id (a queue
 * worker, `millwright:update --resume`, an admin page that was just reopened) rebuild all of
 * this and carry on where another process stopped.
 */
class RunContext
{
    public function __construct(
        public readonly TranslatorInterface $translator,
        public readonly string $installPath,
        public readonly string $workDir,
        public readonly ComposerRunner $composer,
        public readonly Journal $journal,
        public readonly Forum $forum,
        /** @var list<string> the packages the user asked to change */
        public readonly array $requested = [],
        /** 'update', 'install' for something new, or 'remove' to take one out */
        public readonly string $mode = 'update',
        /*
         * Optional, and every one of them degrades to "cannot check" rather
         * than to "fine". A host that cannot be asked whether its site is up
         * must not have that read as a yes: see SiteCheck::verify().
         */
        public readonly string $vendorPath = '',
        public readonly string $storagePath = '',
        public readonly string $siteUrl = '',
        /**
         * Requirements this run is authorised to RAISE, package => version.
         *
         * A forum that pins `page-builder` to `3.5.1` cannot be moved to 3.6.0
         * by a resolve, however many times somebody presses Update: the
         * constraint forbids it. Raising the pin is the only thing that helps,
         * and it edits composer.json, so it is never inferred: the admin is
         * shown the exact requirement change and it is recorded with the run.
         *
         * @var array<string,string>
         */
        public readonly array $repin = [],
    ) {
    }

    /** vendor/, wherever this forum keeps it. */
    public function vendor(): string
    {
        return $this->vendorPath !== '' ? $this->vendorPath : $this->installPath.'/vendor';
    }

    /** @return list<Change> */
    public function plan(): array
    {
        return array_map(
            fn (array $row) => Change::fromArray($row),
            $this->planFile()['changes'] ?? []
        );
    }

    /** @return list<Change> what has an archive to download: everything but a removal */
    public function downloadable(): array
    {
        $sources = $this->planFile()['sources'] ?? [];

        return array_values(array_filter(
            $this->plan(),
            fn (Change $c) => $c->op !== Change::REMOVE && isset($sources[$c->package])
        ));
    }

    public function hasPlan(): bool
    {
        return is_file($this->workDir.'/plan.json');
    }

    /** @return array<string,mixed> */
    public function planFile(): array
    {
        return $this->hasPlan() ? self::readJson($this->workDir.'/plan.json') : [];
    }

    /** @return array<string,mixed> */
    public static function readJson(string $path): array
    {
        return (array) json_decode((string) file_get_contents($path), true);
    }

    /**
     * Wait for a fresh request before running post-swap code in this process.
     * A no-op wherever a subprocess does the work.
     */
    public function needsFreshCode(): void
    {
        if (! $this->composer->canSpawn()) {
            (new FreshCode($this->translator, $this->workDir, $this->installPath, new Opcache($this->translator)))->ensure();
        }
    }

    /** The undo, or null when this run was not given the paths it needs. */
    public function restore(): ?Restore
    {
        if ($this->vendorPath === '' || $this->storagePath === '') {
            return null;
        }

        return new Restore(
            $this->translator,
            $this->forum,
            $this->vendorPath,
            $this->installPath,
            $this->workDir,
            StorageDir::of($this->storagePath).'/trash',
            $this->journal,
            $this->composer,
            $this->storagePath
        );
    }
}
