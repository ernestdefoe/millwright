<?php

namespace ErnestDefoe\Millwright\Work;

use ErnestDefoe\Millwright\Apply\Applier;
use ErnestDefoe\Millwright\Plan\Change;
use ErnestDefoe\Millwright\Run\Run;
use ErnestDefoe\Millwright\Run\Steps;
use GuzzleHttp\ClientInterface;
use RuntimeException;

/**
 * A real update, broken into pieces small enough that no single one needs a long
 * request.
 *
 * This is where the four phases stop being an idea and become Composer, HTTP and
 * renames. This class only says what the units are and in what order, and does
 * the two that are one call each (download a package, swap a package). The
 * work of the others is in Planner, SiteCheck and Finaliser; the difficulty of
 * making each unit small, repeatable and honest is in LockDiff, Fetcher and
 * Applier.
 *
 * Every doItem() has to be safe to run twice. The driver saves progress AFTER
 * the work, so a process killed in between asks for the same item again on
 * resume. Fetch checks whether the package is already staged; apply's stash is a
 * no-op when the source is already gone; the finalise commands are all
 * idempotent by nature. That is a property to preserve, not an accident.
 */
class ComposerSteps implements Steps
{
    public function __construct(
        protected RunContext $run,
        protected Planner $planner,
        protected Fetcher $fetcher,
        protected Applier $applier,
        protected SiteCheck $site,
        protected Finaliser $finaliser,
    ) {
    }

    /** One run's steps, with its phases wired to each other. */
    public static function for(RunContext $run, Fetcher $fetcher, Applier $applier, ClientInterface $http): self
    {
        $planner = new Planner($run);
        $site = new SiteCheck($run, $http);

        return new self($run, $planner, $fetcher, $applier, $site, new Finaliser($run, $planner, $site, $http));
    }

    public function itemsFor(string $phase, Run $run): array
    {
        return match ($phase) {
            'plan' => ['check the site', 'work out what changes', 'check file permissions'],
            'fetch' => array_map(fn (Change $c) => $c->package, $this->run->downloadable()),
            'apply' => array_map(fn (Change $c) => $c->package, $this->applyOrder()),
            /*
             * 'tidy the trash' is LAST, after the site has been checked: an
             * update that is about to be undone automatically must not have its
             * housekeeping run first. Its copies would be kept anyway (this
             * run is the newest) but there is no reason to find out.
             *
             * Nothing, when the plan moved nothing. Migrating, publishing
             * assets and clearing every cache on a site that did not change is
             * all cost and no update, and it is what let a run that moved
             * nothing look exactly like one that did (live box, 2026-10-13).
             */
            'finalise' => $this->run->hasPlan() && $this->run->plan() === []
                ? []
                : ['register', 'migrations', 'assets', 'caches', 'code cache', 'check the site again', 'tidy the trash'],
            default => [],
        };
    }

    public function doItem(string $phase, string $item, Run $run): ?string
    {
        return match ($phase) {
            'plan' => match ($item) {
                'check the site' => $this->site->baseline(),
                'check file permissions' => $this->planner->checkPermissions(),
                default => $this->planner->resolve(),
            },
            'fetch' => $this->fetchOne($item),
            'apply' => $this->applyOne($item),
            'finalise' => $this->finaliser->finalise($item, $run),
            default => null,
        };
    }

    protected function fetchOne(string $package): string
    {
        $plan = $this->run->planFile();
        $source = $plan['sources'][$package] ?? null;

        if ($source === null) {
            throw new RuntimeException(
                $this->run->translator->trans('ernestdefoe-millwright.api.composer.no_archive', ['package' => $package])
            );
        }

        $this->fetcher->fetch($package, $source);

        return $this->run->translator->trans('ernestdefoe-millwright.api.composer.downloaded', ['package' => $package]);
    }

    /**
     * The plan in the order it is applied: Flarum core LAST.
     *
     * A host without processes applies one package per request, and the
     * moment core's files land every later request gets "Update Flarum".
     * Alphabetically core was 4th of 27 on rc.8 → nightly, so the other 23
     * swaps — and the database update after them — could never run. Last, it
     * is swapped in the same request that brings the database up to date.
     *
     * @return list<Change>
     */
    protected function applyOrder(): array
    {
        $plan = $this->run->plan();
        usort($plan, fn (Change $a, Change $b) => (int) ($a->package === 'flarum/core') <=> (int) ($b->package === 'flarum/core'));

        return $plan;
    }

    protected function applyOne(string $package): string
    {
        $plan = $this->applyOrder();

        foreach ($plan as $change) {
            if ($change->package === $package) {
                $done = $this->applier->applyOne($change);

                /*
                 * In this request, before anything else can fail. A package
                 * with `autoload.files` that this swap took away (a removal, or
                 * a new version without that file) would otherwise kill every
                 * request in Composer's autoloader, this updater's next step and
                 * its undo included. See AutoloadFiles.
                 */
                if (AutoloadFiles::pruneMissing($this->run->installPath.'/vendor') !== []) {
                    $done .= '. '.$this->run->translator->trans('ernestdefoe-millwright.api.composer.autoload_files_pruned');
                }

                $this->site->dropStaleCode();

                // The last swap of an update that moved Flarum itself.
                if ($package === end($plan)->package && $this->movesCore($plan)) {
                    $done .= '. '.$this->finaliser->bridgeCore();
                }

                return $done;
            }
        }

        throw new RuntimeException($this->run->translator->trans('ernestdefoe-millwright.api.composer.not_in_plan', ['package' => $package]));
    }

    /** @param list<Change> $plan */
    protected function movesCore(array $plan): bool
    {
        foreach ($plan as $change) {
            if ($change->package === 'flarum/core' && $change->op === Change::REPLACE) {
                return true;
            }
        }

        return false;
    }
}
