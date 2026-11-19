<?php

namespace ErnestDefoe\Millwright\Api\Controller;

use ErnestDefoe\Millwright\Config\ConfigState;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Where Composer looks, what it will accept, and how it authenticates.
 *
 * The three settings that decide whether Composer can find anything at all.
 * Without them Millwright can update what is already installed and nothing
 * else. Each change has its own endpoint (see Config\*Controller); this reads.
 */
class ConfigController implements RequestHandlerInterface
{
    public function __construct(protected ConfigState $state)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        return new JsonResponse($this->state->toArray());
    }
}
