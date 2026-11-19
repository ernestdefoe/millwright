<?php

namespace ErnestDefoe\Millwright\Api\Controller\Config;

use ErnestDefoe\Millwright\Config\ConfigState;
use ErnestDefoe\Millwright\Config\Repositories;
use Illuminate\Support\Arr;

/** Add a place Composer looks for packages. */
class AddRepositoryController extends ConfigChange
{
    public function __construct(protected Repositories $repositories, ConfigState $state)
    {
        $this->state = $state;
    }

    protected function change(array $body): void
    {
        $this->repositories->add(
            (string) Arr::get($body, 'type', 'vcs'),
            (string) Arr::get($body, 'url', ''),
            Arr::get($body, 'name')
        );
    }
}
