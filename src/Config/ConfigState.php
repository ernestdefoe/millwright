<?php

namespace ErnestDefoe\Millwright\Config;

use Flarum\Locale\TranslatorInterface;

/**
 * Where Composer looks, what it will accept, and how it authenticates, as the
 * Composer settings screen shows them. Every change answers with this, so
 * the screen redraws from what is now on disk.
 *
 * No stored credential is ever in it. The auth section reports which hosts
 * have one; the values are write-only by construction. See AuthTokens.
 */
class ConfigState
{
    public function __construct(
        protected TranslatorInterface $translator,
        protected Repositories $repositories,
        protected Stability $stability,
        protected AuthTokens $auth,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $stability = $this->stability;
        $current = $stability->current();

        return [
            'repositories' => $this->repositories->all(),
            'stability' => $current + [
                'levels' => Stability::LEVELS,
            ],
            'auth' => [
                'stored' => $this->auth->all(),
                'kinds' => AuthTokens::KINDS,
                'path' => 'auth.json',
            ],
            'types' => Repositories::TYPES,
            /*
             * A change here does not re-resolve anything. Composer reads these
             * on the NEXT install or update, so saying so is the difference
             * between somebody understanding why nothing happened and somebody
             * concluding the screen is broken.
             */
            'note' => $this->translator->trans('ernestdefoe-millwright.api.config.takes_effect_next_time'),
        ];
    }
}
