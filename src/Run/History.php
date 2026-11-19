<?php

namespace ErnestDefoe\Millwright\Run;

use ErnestDefoe\Millwright\Host\StateFile;
/**
 * What changed on this forum, and when: the list an app store shows as
 * "recently updated" (ClaudiusH's idea, 2026-10-07).
 *
 * Its own small file rather than read from the run records, because the
 * pruner removes run folders once no undo can use them — and "what changed
 * last month" is exactly the question people ask after that. One entry per
 * run, written when the run reaches an end (done, failed, undone); an undo
 * updates its run's entry instead of adding a second. The newest LIMIT kept.
 */
class History
{
    protected const LIMIT = 50;

    public function __construct(protected string $millwrightDir)
    {
    }

    public function record(Run $run): void
    {
        if (! $run->isFinished()) {
            return;
        }

        $runDir = $this->millwrightDir.'/runs/'.$run->id;
        $plan = StateFile::read($runDir.'/plan.json');
        $requested = StateFile::read($runDir.'/requested.json');
        $migrations = StateFile::read($runDir.'/migrations.json');

        $entries = array_values(array_filter($this->all(), fn ($e) => ($e['id'] ?? null) !== $run->id));
        $previous = array_values(array_filter($this->all(), fn ($e) => ($e['id'] ?? null) === $run->id))[0] ?? [];

        array_unshift($entries, [
            'id' => $run->id,
            'at' => $run->movedAt,
            'state' => $run->state,
            'mode' => (string) ($requested['mode'] ?? 'update'),
            // An undo loses nothing: what the update changed is kept from before.
            'changes' => (array) ($plan['changes'] ?? ($previous['changes'] ?? [])),
            'requested' => array_values(array_filter((array) ($requested['packages'] ?? ($previous['requested'] ?? [])), 'is_string')),
            'migrations' => count($migrations) ?: (int) ($previous['migrations'] ?? 0),
        ]);

        /*
         * The run itself is already saved, so a history that cannot be written
         * must not fail it: logged instead, and the Recent updates tab simply
         * lacks the line.
         */
        if (! StateFile::write($this->path(), array_slice($entries, 0, self::LIMIT))) {
            error_log('[millwright] could not write the update history at '.$this->path());
        }
    }

    /** @return list<array<string,mixed>> newest first */
    public function all(): array
    {
        return array_values(StateFile::read($this->path()));
    }

    protected function path(): string
    {
        return $this->millwrightDir.'/history.json';
    }
}
