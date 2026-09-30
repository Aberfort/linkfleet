<?php

namespace App\Console\Commands;

use App\Billing\PlanCatalog;
use App\Models\Workspace;
use Illuminate\Console\Command;

class BillingGrant extends Command
{
    protected $signature = 'billing:grant
        {workspace : The workspace id}
        {plan? : A plan key from config/billing.php}
        {--clear : Take a granted plan away}';

    protected $description = 'Give a workspace a plan without a subscription (or take one away)';

    public function handle(PlanCatalog $catalog): int
    {
        $workspace = Workspace::find($this->argument('workspace'));

        if (! $workspace) {
            $this->error('There is no workspace with that id.');

            return self::FAILURE;
        }

        if ($this->option('clear')) {
            $workspace->forceFill(['granted_plan' => null])->save();
            $this->info("Workspace #{$workspace->id} ({$workspace->name}) no longer has a granted plan.");

            return self::SUCCESS;
        }

        $plan = $catalog->find($this->argument('plan'));

        if ($plan === null || $plan->key === $catalog->free()->key) {
            $options = $catalog->all()->keys()->reject(fn ($key) => $key === 'free')->implode(', ');
            $this->error("Give a plan to grant: {$options} (or --clear).");

            return self::FAILURE;
        }

        $workspace->forceFill(['granted_plan' => $plan->key])->save();
        $this->info("Workspace #{$workspace->id} ({$workspace->name}) now has the {$plan->name} plan.");

        if (! $catalog->enabled()) {
            $this->warn('BILLING_ENABLED is off here, so this has no effect until it is turned on.');
        }

        return self::SUCCESS;
    }
}
