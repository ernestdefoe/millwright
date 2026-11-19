<?php

namespace ErnestDefoe\Millwright\Api\Controller;

use Flarum\Http\RequestUtil;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** Remove the rollback copies no rollback can reach, by the same rule the report quoted. */
class PruneTrashController extends TrashController
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        return $this->report($this->pruner->prune('admin'));
    }
}
