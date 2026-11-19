<?php

namespace ErnestDefoe\Millwright\Api\Controller;

use ErnestDefoe\Millwright\Prune\Pruner;
use ErnestDefoe\Millwright\Prune\Retention;
use Flarum\Http\RequestUtil;
use Flarum\Locale\TranslatorInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The rollback copies: how much room they take, what a prune would free, and
 * the button that does it.
 *
 * This is a dry run and nothing else. It is what the screen's confirm quotes,
 * so the number somebody agrees to is the number the same rule just computed —
 * and PruneTrashController recomputes it rather than trusting a list the
 * browser sends back. The browser asks WHETHER to prune, never WHAT.
 *
 * Not part of /millwright/state on purpose: that is polled every couple of
 * seconds during an update, and sizing the trash walks every file in it.
 */
class TrashController implements RequestHandlerInterface
{
    public function __construct(
        protected TranslatorInterface $translator,
        protected Pruner $pruner,
        protected Retention $retention,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        return $this->report(null);
    }

    /**
     * What is kept, and what a prune would free now.
     *
     * @param array<string,mixed>|null $pruned the summary of a prune just made, if one was
     */
    protected function report(?array $pruned): ResponseInterface
    {
        $retention = $this->retention;
        $pruner = $this->pruner;

        $plan = $pruner->plan(true);

        $keptTrash = array_values(array_filter($plan['keep'], fn ($r) => $r['kind'] === 'trash'));

        return new JsonResponse([
            'settings' => $plan['settings'],
            'trashBytes' => $plan['trashBytes'],
            'trashCount' => count($keptTrash) + count(array_filter($plan['remove'], fn ($r) => $r['kind'] === 'trash')),
            'removeCount' => count($plan['remove']),
            'removeBytes' => $plan['removeBytes'],
            'lastPrune' => $retention->lastPrune(),
            'pruned' => $pruned,
        ]);
    }
}
