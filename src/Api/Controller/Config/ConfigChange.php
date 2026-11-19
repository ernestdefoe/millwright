<?php

namespace ErnestDefoe\Millwright\Api\Controller\Config;

use ErnestDefoe\Millwright\Config\ConfigState;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * One change to Composer's settings, answered with the settings as they now
 * are. Admins only.
 *
 * The Config classes refuse a value that cannot be used with a
 * RefusedException written for the person who typed it: "that is not a
 * hostname" is actionable, "invalid input" is not.
 */
abstract class ConfigChange implements RequestHandlerInterface
{
    protected ConfigState $state;

    /** @param array<string,mixed> $body */
    abstract protected function change(array $body): void;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $this->change((array) $request->getParsedBody());

        return new JsonResponse($this->state->toArray());
    }
}
