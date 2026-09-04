<?php

use ErnestDefoe\Millwright\Api\Controller;
use ErnestDefoe\Millwright\MillwrightServiceProvider;
use Flarum\Extend;

return [
    (new Extend\ServiceProvider())
        ->register(MillwrightServiceProvider::class),

    (new Extend\Frontend('admin'))
        ->js(__DIR__ . '/js/dist/admin.js')
        ->css(__DIR__ . '/less/admin.less'),

    new Extend\Locales(__DIR__ . '/resources/locale'),

    /*
     * 🚨 Two endpoints, and the split matters.
     *
     * `state` is a read: the admin screen polls it, and it must stay cheap
     * enough to poll every second or two without being noticed.
     *
     * `step` does exactly one unit of work and returns. That is the whole
     * design — progress is a function of how many times this is called, so a
     * host that cuts every request at thirty seconds can still finish an update
     * that takes ten minutes. Nothing here ever loops.
     */
    (new Extend\Routes('api'))
        ->get('/millwright/state', 'millwright.state', Controller\StateController::class)
        ->post('/millwright/update', 'millwright.update', Controller\StartController::class)
        ->post('/millwright/step', 'millwright.step', Controller\StepController::class)
        ->post('/millwright/rollback', 'millwright.rollback', Controller\RollbackController::class),
];
