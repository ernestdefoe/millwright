<?php

namespace ErnestDefoe\Millwright\Api\Controller\Config;

use ErnestDefoe\Millwright\Host\PhpOverride;
use ErnestDefoe\Millwright\Run\RunStore;
use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Flarum\Locale\TranslatorInterface;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The command-line PHP, for the hosts where looking for one cannot work
 * (open_basedir, an unusual layout). Refused unless it runs and says it is
 * the CLI. An empty path goes back to finding one.
 */
class PhpPathController implements RequestHandlerInterface
{
    public function __construct(
        protected TranslatorInterface $translator,
        protected PhpOverride $override,
        protected RunStore $runs,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();
        ConfigChange::refuseWhileRunning($this->runs, $this->translator);

        $path = trim((string) Arr::get((array) $request->getParsedBody(), 'path', ''));

        if ($path !== '' && ($refusal = $this->override->refusal($path)) !== null) {
            throw new ValidationException(['path' => $this->translator->trans('ernestdefoe-millwright.api.php_path.'.$refusal[0], $refusal[1])]);
        }

        $this->override->set($path === '' ? null : $path);

        return new JsonResponse(['php' => $this->override->get()]);
    }
}
