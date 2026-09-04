<?php

namespace ErnestDefoe\Millwright\Api\Controller;

use ErnestDefoe\Millwright\Apply\Journal;
use ErnestDefoe\Millwright\Apply\Rollback;
use ErnestDefoe\Millwright\Run\RunStore;
use ErnestDefoe\Millwright\Work\WorkDir;
use Flarum\Foundation\Paths;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Put everything back the way it was.
 *
 * 🚨 Works on a finished run AND on one that died halfway. That is the whole
 * claim: the journal records what was about to happen before it happened, so an
 * interrupted update is as recoverable as a completed one. There is no state
 * this refuses to unwind.
 */
class RollbackController implements RequestHandlerInterface
{
    public function __construct(
        private RunStore $runs,
        private Paths $paths,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $run = $this->runs->latest();

        if ($run === null) {
            return new JsonResponse(['error' => 'There is nothing to roll back.'], 404);
        }

        $workDir = new WorkDir($this->paths->storage, $run->id);
        $journal = new Journal($workDir->journalPath());

        if (! $journal->exists()) {
            return new JsonResponse([
                'error' => 'That update never got as far as changing anything, so there is nothing to undo.',
            ], 422);
        }

        $undone = (new Rollback(
            $this->paths->vendor,
            $workDir->trash(),
            $journal,
            $this->paths->base,
            $workDir->root()
        ))->run();

        $this->runs->save($run->rolledBack(time(), $undone));

        /*
         * 🚨 The autoloader has to be rebuilt after this, and it is left to the
         * caller rather than done here: it means booting Flarum in the request
         * that just moved its files, which is the half-old class map problem
         * that takes sites down. The screen tells the admin to finish with one
         * command, which is honest about the seam rather than hiding it.
         */
        return new JsonResponse([
            'undone' => $undone,
            'run'    => $this->runs->latest()?->toArray(),
            'next'   => $undone === []
                ? null
                : 'Run `composer dump-autoload` to finish putting things back.',
        ]);
    }
}
