<?php

namespace App\Http\Controllers;

use App\Billing\PlanCatalog;

class ConfigController extends Controller
{
    /**
     * Public, unauthenticated - lets the frontend know at runtime whether
     * to show the register flow, whether this is the hosted edition with
     * plans, and where custom domains should point, without needing a
     * rebuild to change any of it.
     */
    public function index(PlanCatalog $plans)
    {
        $billing = $plans->enabled();

        return [
            'registration_enabled' => (bool) config('features.registration_enabled'),
            'custom_domain_target' => config('features.custom_domain_target'),
            'billing' => [
                'enabled' => $billing,
                // Both are meant for the browser (Paddle.js needs them); only
                // sent when there is something to sell.
                ...($billing ? [
                    'client_side_token' => config('cashier.client_side_token'),
                    'sandbox' => (bool) config('cashier.sandbox'),
                ] : []),
            ],
        ];
    }
}
