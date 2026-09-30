<?php

/*
|--------------------------------------------------------------------------
| Plans and billing (the hosted edition)
|--------------------------------------------------------------------------
|
| Off by default, and off means off: a self-hosted install has no plans, no
| limits and no billing screens. Every feature is in every edition - what a
| plan sells is *room* (links, domains, people), never a locked feature.
|
| The numbers below are starting points. Change them freely; nothing else
| in the code knows them.
|
*/

return [
    'enabled' => (bool) env('BILLING_ENABLED', false),

    /*
     * In ascending order: a later plan outranks an earlier one, which is how
     * "the best of what this workspace has been given and has bought" is
     * decided. `free` must exist and must have no prices. A limit of null
     * means unlimited.
     *
     * `prices` are Paddle price ids (pri_...), created in the Paddle
     * dashboard. A plan with none is not for sale.
     */
    'plans' => [
        'free' => [
            'name' => 'Free',
            'limits' => ['links' => 25, 'domains' => 0, 'members' => 1],
        ],
        'pro' => [
            'name' => 'Pro',
            'limits' => ['links' => 1_000, 'domains' => 3, 'members' => 3],
            'prices' => [
                'monthly' => env('PADDLE_PRICE_PRO_MONTHLY'),
                'yearly' => env('PADDLE_PRICE_PRO_YEARLY'),
            ],
        ],
        'team' => [
            'name' => 'Team',
            'limits' => ['links' => 10_000, 'domains' => 10, 'members' => 15],
            'prices' => [
                'monthly' => env('PADDLE_PRICE_TEAM_MONTHLY'),
                'yearly' => env('PADDLE_PRICE_TEAM_YEARLY'),
            ],
        ],
    ],

    // How old a webhook's signature may be, in seconds. Paddle's own SDKs
    // use 5; this is looser so that a few seconds of clock drift between
    // Paddle and this server cannot silently stop every subscription update.
    // Replays inside the window do nothing: every handler is idempotent.
    'webhook_tolerance' => (int) env('PADDLE_WEBHOOK_TOLERANCE', 60),
];
