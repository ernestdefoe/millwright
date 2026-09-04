<?php

namespace ErnestDefoe\Millwright\Api\Controller;

use ErnestDefoe\Millwright\Run\Drivers;
use ErnestDefoe\Millwright\Run\RunStore;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Advance the current run by one unit of work.
 *
 * 🚨 There is nothing to advance yet, and this says so rather than pretending.
 *
 * The driver behind this is built and tested — see StepRunner and the crash
 * suite — but the work it drives is Composer resolution, download and install,
 * which is the next phase. Wiring a button to a pipeline with no steps in it
 * would produce exactly the failure this whole extension exists to replace: a
 * control that looks like it did something and did not.
 */
class StepController implements RequestHandlerInterface
{
    public function __construct(
        private RunStore $runs,
        private Drivers $drivers,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $run = $this->runs->latest();

        if ($run === null) {
            return new JsonResponse([
                'run'      => null,
                'ready'    => false,
                'why'      => 'Millwright can inspect this host, but it cannot run updates yet — Composer support is the next piece of work.',
                'driver'   => $this->drivers->describe(),
                'hasWorker' => $this->drivers->hasWorker(),
            ]);
        }

        return new JsonResponse([
            'run'       => $run->toArray(),
            'ready'     => false,
            'driver'    => $this->drivers->describe(),
            'hasWorker' => $this->drivers->hasWorker(),
        ]);
    }
}
