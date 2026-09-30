<?php

namespace App\Console\Commands;

use App\Billing\LimitedResource;
use App\Billing\PlanCatalog;
use Illuminate\Console\Command;

class BillingCheck extends Command
{
    protected $signature = 'billing:check';

    protected $description = 'Say whether this deployment is ready to take payments, and what is missing';

    public function handle(PlanCatalog $catalog): int
    {
        if (! $catalog->enabled()) {
            $this->line('BILLING_ENABLED is off: this is a self-hosted install, with no plans and no limits.');

            return self::SUCCESS;
        }

        $this->info('Billing is on ('.(config('cashier.sandbox') ? 'Paddle sandbox' : 'Paddle live').').');
        $this->newLine();

        $this->table(
            ['Plan', 'Links', 'Domains', 'Members', 'Prices'],
            $catalog->all()->map(fn ($plan) => [
                $plan->name,
                $plan->limit(LimitedResource::Links) ?? '∞',
                $plan->limit(LimitedResource::Domains) ?? '∞',
                $plan->limit(LimitedResource::Members) ?? '∞',
                $plan->isForSale() ? implode(', ', array_keys($plan->prices)) : '— (not for sale)',
            ])->all(),
        );

        $this->line('Point the Paddle notification destination at: '.url('/api/paddle/webhook'));
        $this->line('Events to send: customer.updated, transaction.completed, transaction.updated,');
        $this->line('subscription.created, subscription.updated, subscription.paused, subscription.canceled');
        $this->newLine();

        $missing = $catalog->missingSettings();

        if ($missing === []) {
            $this->info('Everything needed to take a payment is set.');

            return self::SUCCESS;
        }

        $this->error('Still missing: '.implode(', ', $missing));

        return self::FAILURE;
    }
}
