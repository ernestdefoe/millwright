<?php

namespace ErnestDefoe\Millwright\Api\Controller;

use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** How long rollback copies are kept: the last N days and the last N runs. */
class TrashSettingsController extends TrashController
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $body = (array) $request->getParsedBody();
        $this->retention->save(Arr::get($body, 'keepDays'), Arr::get($body, 'keepRuns'));

        return $this->report(null);
    }
}
