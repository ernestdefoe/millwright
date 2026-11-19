<?php

namespace ErnestDefoe\Millwright\Apply;

use RuntimeException;

/**
 * The path handling the applier and the rollback share, written once.
 *
 * Both move package folders in and out of vendor/, and two copies of "join
 * a path" or "tidy an empty vendor folder" is two places for a fix to land in
 * only one (see Tree for the time that happened).
 *
 * @property \Flarum\Locale\TranslatorInterface $translator
 * @property string $vendorDir
 */
trait VendorPaths
{
    /**
     * Join a trusted base with a relative path that has already been
     * validated by Change::relativePath(). Kept in one place so there is one
     * thing to audit rather than five string concatenations.
     */
    protected function path(string $base, string $relative): string
    {
        return rtrim($base, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$relative;
    }

    protected function ensureDir(string $dir): void
    {
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException($this->translator->trans('ernestdefoe-millwright.api.fs.create_failed', ['path' => $dir]));
        }
    }

    /**
     * Remove a vendor namespace directory if the last package in it has gone.
     *
     * Only when empty, and never above the vendor directory itself. rmdir
     * refuses a non-empty directory, so the check and the act cannot disagree
     * even if something is written between them. An empty vendor/acme/ left
     * behind is not wrong so much as unfinished, and it is the sort of litter
     * that makes somebody wonder what else was left half done.
     */
    protected function tidyVendorDir(string $dir): void
    {
        $vendor = rtrim($this->vendorDir, DIRECTORY_SEPARATOR);

        if ($dir === $vendor || ! str_starts_with($dir, $vendor.DIRECTORY_SEPARATOR)) {
            return;
        }

        @rmdir($dir);
    }
}
