<?php

namespace ErnestDefoe\Millwright\Api\Controller;

use ErnestDefoe\Millwright\Host\Capability;
use ErnestDefoe\Millwright\Run\Drivers;
use ErnestDefoe\Millwright\Run\RunStore;
use ErnestDefoe\Millwright\Run\StepRunner;
use ErnestDefoe\Millwright\Work\WorkDir;
use Flarum\Foundation\Paths;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Start an update.
 *
 * 🚨 Every refusal here says why, in words aimed at the person reading them.
 * The behaviour being replaced refuses silently when anything looks busy, which
 * is how one dead task blocks every later update forever and the admin is left
 * looking at a spinner with no explanation anywhere.
 */
class StartController implements RequestHandlerInterface
{
    public function __construct(
        private RunStore $runs,
        private StepRunner $runner,
        private Drivers $drivers,
        private Paths $paths,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $body = (array) $request->getParsedBody();
        $packages = array_values(array_filter((array) Arr::get($body, 'packages', [])));
        $mode = Arr::get($body, 'mode') === 'install' ? 'install' : 'update';

        foreach ($packages as $package) {
            // The only user-supplied value that reaches a command line.
            if (! preg_match('#^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$#', (string) $package)) {
                return new JsonResponse(['error' => "That is not a package name: $package"], 422);
            }
        }

        $capability = new Capability($this->paths->base);

        if ($packages === []) {
            return new JsonResponse(['error' => 'Nothing was selected, so nothing was started.'], 422);
        }

        if ($capability->resolveTier() === Capability::NONE) {
            return new JsonResponse([
                'error' => 'This host does not have enough memory for Composer to work out what an update involves. '
                    . 'Nothing was started. Ask your host to raise memory_limit to 256 MB and try again.',
            ], 422);
        }

        $existing = $this->runs->latest();

        if ($existing !== null && ! $existing->isFinished()) {
            /*
             * 🚨 Refused, but LOUDLY, and with the age of the thing in the way
             * so the admin can tell a working update from a dead one. The screen
             * offers to abandon it; this endpoint never decides that for them.
             */
            $age = time() - $existing->movedAt;

            return new JsonResponse([
                'error' => 'An update is already in progress'
                    . ($age > 120 ? ", though nothing has moved for " . round($age / 60) . " minutes." : '.'),
                'run'   => $existing->toArray(),
                'stale' => $existing->isStale(time()),
            ], 409);
        }

        $id = 'r' . date('Ymd-His') . '-' . bin2hex(random_bytes(3));

        (new WorkDir($this->paths->storage, $id))->create()->remember($packages, $mode);

        $run = $this->runner->begin($id);

        // A worker if there is one; the page keeps polling either way.
        $queued = $this->drivers->nudge($id);

        return new JsonResponse([
            'run'    => $run->toArray(),
            'queued' => $queued,
            'driver' => $this->drivers->describe(),
        ]);
    }
}
