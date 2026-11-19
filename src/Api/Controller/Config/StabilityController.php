<?php

namespace ErnestDefoe\Millwright\Api\Controller\Config;

use ErnestDefoe\Millwright\Config\ConfigState;
use ErnestDefoe\Millwright\Config\Stability;
use ErnestDefoe\Millwright\Run\RunStore;
use Flarum\Locale\TranslatorInterface;
use Illuminate\Support\Arr;

/** How finished a release must be for Composer to take it. */
class StabilityController extends ConfigChange
{
    public function __construct(
        protected Stability $stability,
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
        $this->stability->set(
            (string) Arr::get($body, 'minimumStability', 'stable'),
            (bool) Arr::get($body, 'preferStable', true)
        );
    }
}
