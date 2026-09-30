<?php

namespace App\Http\Controllers;

use App\Billing\PlanCatalog;

class PlanController extends Controller
{
    /**
     * The public price list: what each plan includes and which Paddle price
     * ids buy it. Amounts are not here on purpose - Paddle.js prices them for
     * the visitor's own country and currency, in the browser.
     */
    public function index(PlanCatalog $plans)
    {
        return [
            'plans' => $plans->all()->map->toArray()->values(),
            // False until the Paddle keys are all in place; the UI then shows
            // the plans without pretending anyone can buy them yet.
            'checkout_available' => $plans->missingSettings() === [],
        ];
    }
}
