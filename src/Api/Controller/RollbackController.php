<?php

namespace ErnestDefoe\Millwright\Api\Controller;

use ErnestDefoe\Millwright\Exception\RefusedException;
use ErnestDefoe\Millwright\Run\RunStore;
use ErnestDefoe\Millwright\Run\StepRunner;
use ErnestDefoe\Millwright\Work\ComposerStepsFactory;
use Flarum\Http\RequestUtil;
use Flarum\Locale\TranslatorInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Put everything back the way it was.
 *
 * Works on a finished run AND on one that died halfway. That is the whole
 * claim: the journal records what was about to happen before it happened, so an
 * interrupted update is as recoverable as a completed one. There is no state
 * this refuses to unwind.
 */
class RollbackController implements RequestHandlerInterface
{
    public function __construct(
        protected TranslatorInterface $translator,
        protected RunStore $runs,
        protected ComposerStepsFactory $factory,
        protected StepRunner $runner,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $run = $this->runs->latest();

        if ($run === null) {
            throw new RefusedException($this->translator->trans('ernestdefoe-millwright.api.rollback.nothing'), 'nothing_to_roll_back', 404);
        }

        $restore = $this->factory->restoreFor($run->id);

        /*
         * An empty journal does NOT mean nothing changed.
         *
         * The plan phase runs Composer with --no-install, and Composer rewrites
         * composer.json and composer.lock the moment it succeeds, before a
         * single file has been moved, so before the journal has anything in it.
         * A run that then fails leaves the manifests describing a site that does
         * not exist, and the version of this that refused on an empty journal
         * said "that update never got as far as changing anything", which was
         * simply false. Found by a `composer remove` that updated both files and
         * then exited non-zero.
         *
         * So the question is not "is there a journal" but "is there anything
         * saved to put back", which is what possible() asks.
         */
        if (! $restore->possible()) {
            throw new RefusedException($this->translator->trans('ernestdefoe-millwright.api.rollback.nothing_changed'), 'nothing_to_roll_back');
        }

        /*
         * Holding the run's step lock: a queue worker or another tab stepping
         * this run would otherwise be swapping files in while these are put
         * back. A refusal (a database change that cannot be reversed) changes
         * nothing and reaches the admin as a RefusedException.
         */
        $result = $this->runner->whileHolding($run->id, $this->translator->trans('ernestdefoe-millwright.api.rollback.busy'), function () use ($restore, $run) {
            $result = $restore->run();

            $this->runs->save($run->rolledBack(time(), $this->translator->trans('ernestdefoe-millwright.api.run.rolled_back', [
                'undone' => implode(', ', $result['undone']) ?: $this->translator->trans('ernestdefoe-millwright.api.run.nothing_to_undo'),
            ])));

            return $result;
        });

        return new JsonResponse([
            'undone' => $result['undone'],
            'run' => $this->runs->present($this->runs->latest()),
            'next' => $result['note'],
        ]);
    }
}
