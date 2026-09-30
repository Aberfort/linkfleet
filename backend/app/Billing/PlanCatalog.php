<?php

namespace App\Billing;

use Illuminate\Support\Collection;

/**
 * The plans on offer, read from config/billing.php. Cheap enough to build
 * whenever it is needed, which keeps a test's `config([...])` honest.
 */
final class PlanCatalog
{
    public const SELF_HOSTED = 'self-hosted';

    /** Whether this deployment sells anything. Off is the self-hosted default. */
    public function enabled(): bool
    {
        return (bool) config('billing.enabled');
    }

    /** @return Collection<string, Plan> in ascending rank, keyed by plan key */
    public function all(): Collection
    {
        $plans = collect();
        $rank = 0;

        foreach ((array) config('billing.plans') as $key => $definition) {
            $key = (string) $key;

            $plans->put($key, new Plan(
                key: $key,
                name: (string) ($definition['name'] ?? ucfirst($key)),
                limits: (array) ($definition['limits'] ?? []),
                // An unset env var is a null here; a plan is only as for-sale
                // as the prices that were actually filled in.
                prices: array_filter((array) ($definition['prices'] ?? []), fn ($id) => is_string($id) && $id !== ''),
                rank: $rank++,
            ));
        }

        return $plans;
    }

    public function find(?string $key): ?Plan
    {
        return $key === null ? null : $this->all()->get($key);
    }

    /** What everyone gets who has neither bought nor been given something. */
    public function free(): Plan
    {
        return $this->find('free') ?? throw new \LogicException('config/billing.php must define a "free" plan.');
    }

    /**
     * The plan of a deployment that sells nothing: no ceilings at all.
     */
    public function unlimited(): Plan
    {
        return new Plan(self::SELF_HOSTED, 'Self-hosted', [], [], PHP_INT_MAX);
    }

    /** @return Collection<string, Plan> */
    public function forSale(): Collection
    {
        return $this->all()->filter(fn (Plan $plan) => $plan->isForSale());
    }

    /** The plan a Paddle price id buys, or null for a price we do not sell. */
    public function forPrice(?string $priceId): ?Plan
    {
        if ($priceId === null || $priceId === '') {
            return null;
        }

        return $this->forSale()->first(fn (Plan $plan) => in_array($priceId, $plan->priceIds(), true));
    }

    /**
     * What is still missing before billing could work, so a half-configured
     * deployment says so instead of failing on the first customer.
     *
     * @return array<int, string>
     */
    public function missingSettings(): array
    {
        $missing = [];

        foreach ([
            'PADDLE_API_KEY' => config('cashier.api_key'),
            'PADDLE_CLIENT_SIDE_TOKEN' => config('cashier.client_side_token'),
            'PADDLE_WEBHOOK_SECRET' => config('cashier.webhook_secret'),
        ] as $name => $value) {
            if (blank($value)) {
                $missing[] = $name;
            }
        }

        if ($this->forSale()->isEmpty()) {
            $missing[] = 'at least one PADDLE_PRICE_* id';
        }

        return $missing;
    }
}
