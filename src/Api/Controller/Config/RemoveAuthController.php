<?php

namespace ErnestDefoe\Millwright\Api\Controller\Config;

use ErnestDefoe\Millwright\Config\ConfigState;
use ErnestDefoe\Millwright\Config\AuthTokens;
use ErnestDefoe\Millwright\Run\RunStore;
use Flarum\Locale\TranslatorInterface;
use Illuminate\Support\Arr;

/** Forget a host's credential. */
class RemoveAuthController extends ConfigChange
{
    public function __construct(
        protected AuthTokens $auth,
        ConfigState $state,
        RunStore $runs,
        TranslatorInterface $translator,
    ) {
        $this->state = $state;
        $this->runs = $runs;
        $this->translator = $translator;
    }

    protected function change(array $body): void
    {
        $this->auth->remove(
            (string) Arr::get($body, 'kind', ''),
            (string) Arr::get($body, 'host', '')
        );
    }
}
