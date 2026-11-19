<?php

namespace ErnestDefoe\Millwright\Api\Controller\Config;

use ErnestDefoe\Millwright\Config\ConfigState;
use ErnestDefoe\Millwright\Config\Repositories;
use Illuminate\Support\Arr;

/** Stop Composer looking in a repository. */
class RemoveRepositoryController extends ConfigChange
{
    public function __construct(protected Repositories $repositories, ConfigState $state)
    {
        $this->state = $state;
    }

    protected function change(array $body): void
    {
        $this->repositories->remove((string) Arr::get($body, 'url', ''));
    }
}
