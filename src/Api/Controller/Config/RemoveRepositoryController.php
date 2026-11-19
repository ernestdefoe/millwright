<?php

namespace ErnestDefoe\Millwright\Api\Controller\Config;

use ErnestDefoe\Millwright\Config\ConfigState;
use ErnestDefoe\Millwright\Config\Repositories;
use ErnestDefoe\Millwright\Run\RunStore;
use Flarum\Locale\TranslatorInterface;
use Illuminate\Support\Arr;

/** Stop Composer looking in a repository. */
class RemoveRepositoryController extends ConfigChange
{
    public function __construct(
        protected Repositories $repositories,
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
        $this->repositories->remove((string) Arr::get($body, 'url', ''));
    }
}
