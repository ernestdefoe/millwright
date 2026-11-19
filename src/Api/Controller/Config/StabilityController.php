<?php

namespace ErnestDefoe\Millwright\Api\Controller\Config;

use ErnestDefoe\Millwright\Config\ConfigState;
use ErnestDefoe\Millwright\Config\Stability;
use Illuminate\Support\Arr;

/** How finished a release must be for Composer to take it. */
class StabilityController extends ConfigChange
{
    public function __construct(protected Stability $stability, ConfigState $state)
    {
        $this->state = $state;
    }

    protected function change(array $body): void
    {
        $this->stability->set(
            (string) Arr::get($body, 'minimumStability', 'stable'),
            (bool) Arr::get($body, 'preferStable', true)
        );
    }
}
