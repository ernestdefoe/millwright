<?php

namespace ErnestDefoe\Millwright\Api\Controller;

use ErnestDefoe\Millwright\Host\Capability;
use ErnestDefoe\Millwright\Run\RunStore;
use Flarum\Extension\ExtensionManager;
use Flarum\Foundation\Paths;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Everything the admin screen draws, in one read.
 *
 * 🚨 Polled while a run is going, so it stays a read — it never advances
 * anything, never repairs anything, and never has a side effect. Mixing "tell me
 * the state" with "make something happen" is how a page refresh ends up
 * starting a second update.
 */
class StateController implements RequestHandlerInterface
{
    public function __construct(
        private ExtensionManager $extensions,
        private Paths $paths,
        private RunStore $runs,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $run = $this->runs->latest();

        return new JsonResponse([
            'host'       => (new Capability($this->paths->base))->report(),
            'installed'  => $this->installed(),
            'run'        => $run?->toArray(),
            'runIsStale' => $run !== null && $run->isStale(time()),
        ]);
    }

    /** @return list<array<string,mixed>> */
    private function installed(): array
    {
        $out = [];

        foreach ($this->extensions->getExtensions() as $extension) {
            $out[] = [
                'id'      => $extension->getId(),
                'name'    => $extension->getTitle(),
                'package' => $extension->name,
                'version' => $extension->getVersion(),
                'icon'    => $extension->getIcon(),
                'enabled' => $this->extensions->isEnabled($extension->getId()),
                /*
                 * 🚨 Deliberately absent, rather than guessed at: whether an
                 * update exists is a question only a resolve can answer, and
                 * resolving is the plan phase. Showing "up to date" here on no
                 * evidence would be a lie the user could act on.
                 */
                'update'  => null,
            ];
        }

        usort($out, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return $out;
    }
}
