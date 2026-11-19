<?php

namespace ErnestDefoe\Millwright\Run;

use Closure;
use ErnestDefoe\Millwright\Exception\RefusedException;
use ErnestDefoe\Millwright\Work\WorkDir;
use Flarum\Foundation\Paths;
use Flarum\Locale\TranslatorInterface;

/**
 * Starting a run, the one way: from the admin screen and from the command line.
 *
 * Under the 'site' lock, so two starts at once (two tabs, or a tab and a
 * terminal) cannot both see "nothing running" and both begin. Refused, and
 * nothing written, while another run has not finished.
 */
class Starter
{
    public function __construct(
        protected TranslatorInterface $translator,
        protected RunStore $runs,
        protected StepRunner $runner,
        protected Paths $paths,
    ) {
    }

    /**
     * @param list<string> $packages
     * @param Closure(): array<string,string> $repin the requirements this run may raise, worked
     *        out inside the lock, after the in-progress check, because it can refuse too
     */
    public function start(array $packages, string $mode, Closure $repin): Run
    {
        return $this->runner->whileHolding('site', $this->translator->trans('ernestdefoe-millwright.api.start.busy'), function () use ($packages, $mode, $repin) {
            $this->refuseWhileRunning();

            $id = 'r'.date('Ymd-His').'-'.bin2hex(random_bytes(3));

            (new WorkDir($this->translator, $this->paths->storage, $id))->create()->remember($packages, $mode, $repin());

            return $this->runner->begin($id);
        });
    }

    /**
     * Refused, but loudly, and with the age of the thing in the way so the
     * admin can tell a working update from a dead one. The screen reloads the
     * state, which carries the run, and offers to abandon it; starting never
     * decides that for them.
     */
    protected function refuseWhileRunning(): void
    {
        $existing = $this->runs->latest();

        if ($existing === null || $existing->isFinished()) {
            return;
        }

        $age = time() - $existing->movedAt;

        throw new RefusedException($age > 120
            ? $this->translator->trans('ernestdefoe-millwright.api.start.in_progress_stale', ['minutes' => round($age / 60)])
            : $this->translator->trans('ernestdefoe-millwright.api.start.in_progress'), 'run_in_progress', 409);
    }
}
