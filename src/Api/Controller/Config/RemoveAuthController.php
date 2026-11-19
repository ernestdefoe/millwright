<?php

namespace ErnestDefoe\Millwright\Api\Controller\Config;

use ErnestDefoe\Millwright\Config\ConfigState;
use ErnestDefoe\Millwright\Config\AuthTokens;
use Illuminate\Support\Arr;

/** Forget a host's credential. */
class RemoveAuthController extends ConfigChange
{
    public function __construct(protected AuthTokens $auth, ConfigState $state)
    {
        $this->state = $state;
    }

    protected function change(array $body): void
    {
        $this->auth->remove(
            (string) Arr::get($body, 'kind', ''),
            (string) Arr::get($body, 'host', '')
        );
    }
}
