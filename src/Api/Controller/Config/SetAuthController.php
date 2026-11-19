<?php

namespace ErnestDefoe\Millwright\Api\Controller\Config;

use ErnestDefoe\Millwright\Config\ConfigState;
use ErnestDefoe\Millwright\Config\AuthTokens;
use Illuminate\Support\Arr;

/** Store a credential for a host. Write-only: it is never read back. */
class SetAuthController extends ConfigChange
{
    public function __construct(protected AuthTokens $auth, ConfigState $state)
    {
        $this->state = $state;
    }

    protected function change(array $body): void
    {
        $this->auth->set(
            (string) Arr::get($body, 'kind', ''),
            (string) Arr::get($body, 'host', ''),
            (string) Arr::get($body, 'secret', ''),
            Arr::get($body, 'username')
        );
    }
}
