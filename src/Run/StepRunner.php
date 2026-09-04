<?php

namespace ErnestDefoe\Millwright\Run;

use Throwable;

/**
 * Advances a run by exactly one unit of work.
 *
 * 🚨 This is the answer to the constraint that actually matters. Memory was
 * never the thing stopping updates on shared hosting — `max_execution_time` was,
 * and every other limit that punishes one long operation. So nothing here loops:
 * a call does one item and returns. Progress becomes a function of how many
 * times something calls step(), and it stops mattering whether that something is
 * the admin screen polling, a cron tick, or a queue worker.
 *
 * A host with a thirty second ceiling can therefore complete an update that
 * takes ten minutes, which no amount of optimisation would have achieved.
 *
 * Two rules it never breaks:
 *
 *   - **It always says what it did.** Calling step() on a finished run returns
 *     that run unchanged rather than doing nothing quietly. Silence is what makes
 *     a stuck update indistinguishable from a working one.
 *   - **It never skips work because something looks busy.** A stale run is
 *     reported to the caller with its age; it is not a reason to refuse. That
 *     refusal, done silently, is why one dead Extension Manager task blocks every
 *     later update forever.
 */
class StepRunner
{
    /** @param callable():int $clock */
    public function __construct(
        private RunStore $store,
        private Steps $steps,
        private $clock,
    ) {
    }

    public function begin(string $id): Run
    {
        $run = Run::start($id, $this->now());
        $this->store->save($run);

        return $run;
    }

    /**
     * One unit of work. Safe to call as often as you like, from anywhere.
     */
    public function step(string $id): Run
    {
        $run = $this->store->load($id);

        if ($run === null) {
            throw new \RuntimeException("No run called $id");
        }

        if ($run->isFinished()) {
            return $run;
        }

        $phase = $run->phase;
        $item  = null;

        try {
            /*
             * Deciding what a phase consists of is itself a unit of work — on a
             * constrained host, planning IS the expensive step, so it must be
             * allowed a whole request of its own rather than being squeezed in
             * before the first item.
             */
            if ($run->total() === 0 && $run->index === 0) {
                $items = $this->steps->itemsFor($phase, $run);

                if ($items === []) {
                    return $this->leavePhase($run);
                }

                $run = $run->withItems($items, $this->now());
                $this->store->save($run);

                return $run;
            }

            $item = $run->current();

            if ($item === null) {
                return $this->leavePhase($run);
            }

            $note = $this->steps->doItem($phase, $item, $run);

            /*
             * 🚨 Saved AFTER the work, which means a process killed in between
             * repeats this item on resume. That is deliberate and it is why
             * doItem must be idempotent: repeating work is recoverable, skipping
             * it silently is not.
             */
            $run = $run->advanced($this->now(), $note);
            $this->store->save($run);

            if ($run->index >= $run->total()) {
                return $this->leavePhase($run);
            }

            return $run;
        } catch (Throwable $e) {
            $where = $item === null ? "$phase (planning)" : "$phase → $item";
            $run = $run->failed($e->getMessage(), $where, $this->now());
            $this->store->save($run);

            return $run;
        }
    }

    /** Move to the next phase, or finish if that was the last one. */
    private function leavePhase(Run $run): Run
    {
        $order = Run::PHASES;
        $at    = array_search($run->phase, $order, true);
        $next  = $at === false ? null : ($order[$at + 1] ?? null);

        $run = $next === null
            ? $run->finished($this->now())
            : $run->enteredPhase($next, $this->now(), "Finished {$run->phase}");

        $this->store->save($run);

        return $run;
    }

    private function now(): int
    {
        return ($this->clock)();
    }
}
