<?php

namespace ErnestDefoe\Millwright\Api\Controller\Config;

use ErnestDefoe\Millwright\Config\ConfigState;
use ErnestDefoe\Millwright\Exception\RefusedException;
use ErnestDefoe\Millwright\Run\RunStore;
use Flarum\Locale\TranslatorInterface;
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

    protected RunStore $runs;

    protected TranslatorInterface $translator;

    /** @param array<string,mixed> $body */
    abstract protected function change(array $body): void;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();
        self::refuseWhileRunning($this->runs, $this->translator);

        $this->change((array) $request->getParsedBody());

        return new JsonResponse($this->state->toArray());
    }

    /**
     * Not while an update is going. The run planned against these settings,
     * and an undo puts composer.json back as it was before the run, so a
     * change made now would be lost along with the update.
     */
    public static function refuseWhileRunning(RunStore $runs, TranslatorInterface $translator): void
    {
        $run = $runs->latest();

        if ($run !== null && ! $run->isFinished()) {
            throw new RefusedException($translator->trans('ernestdefoe-millwright.api.config.busy'), 'run_in_progress', 409);
        }
    }
}
