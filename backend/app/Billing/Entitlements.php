<?php

namespace App\Billing;

use App\Models\Domain;
use App\Models\Link;
use App\Models\Site;
use App\Models\Workspace;
use Closure;
use Illuminate\Support\Facades\DB;
use Laravel\Paddle\Cashier;
use Laravel\Paddle\Subscription;

/**
 * What one workspace is allowed to have, and how much of it is used.
 *
 * The single place that turns "who has paid for what" into a plan, and the
 * single place that says no. Every creation path (a link, an import row, a
 * domain, a member) asks here, so a limit cannot be forgotten by the next
 * endpoint someone adds.
 */
final class Entitlements
{
    /** @var array{plan: Plan, source: string, subscription: Subscription|null}|null */
    private ?array $resolved = null;

    public function __construct(
        private readonly Workspace $workspace,
        private readonly PlanCatalog $catalog,
    ) {}

    public static function for(Workspace $workspace): self
    {
        return new self($workspace, app(PlanCatalog::class));
    }

    /**
     * What a workspace's subscription is filed under. The payer is a user (one
     * Paddle customer per person, however many workspaces they pay for), so
     * the workspace has to be named in the subscription itself.
     */
    public static function subscriptionType(Workspace|int $workspace): string
    {
        return 'workspace:'.($workspace instanceof Workspace ? $workspace->id : $workspace);
    }

    public function plan(): Plan
    {
        return $this->resolve()['plan'];
    }

    /**
     * Where the plan comes from: `self-hosted` (nothing is sold here),
     * `free`, `subscription` (bought) or `granted` (given by the operator).
     */
    public function source(): string
    {
        return $this->resolve()['source'];
    }

    /**
     * The workspace's live subscription, if it has one - even when a granted
     * plan happens to outrank it, so the owner can still see and manage it.
     */
    public function subscription(): ?Subscription
    {
        return $this->resolve()['subscription'];
    }

    /** Null is unlimited. */
    public function limit(LimitedResource $resource): ?int
    {
        return $this->plan()->limit($resource);
    }

    public function usage(LimitedResource $resource): int
    {
        $sites = Site::query()->where('workspace_id', $this->workspace->id)->select('id');

        return match ($resource) {
            LimitedResource::Links => Link::query()->whereIn('site_id', $sites)->count(),
            LimitedResource::Domains => Domain::query()->whereIn('site_id', $sites)->count(),
            LimitedResource::Members => $this->workspace->members()->count(),
        };
    }

    /**
     * Throws unless $adding more of $resource fits. Adding nothing (a domain
     * replacing another) always fits, even for a workspace that has slipped
     * over its limit by downgrading - it is only ever growth that is refused.
     *
     * @throws PlanLimitReached
     */
    public function ensureRoom(LimitedResource $resource, int $adding = 1): void
    {
        $limit = $this->limit($resource);

        if ($limit === null || $adding <= 0) {
            return;
        }

        $usage = $this->usage($resource);

        if ($usage + $adding > $limit) {
            throw new PlanLimitReached($resource, $this->plan(), $limit, $usage, $this->workspace);
        }
    }

    /**
     * Runs $create if it fits, and holds the workspace's row while it does,
     * so two requests arriving together cannot both take the last slot.
     * (Where the plan has no ceiling there is nothing to race for.)
     *
     * @template T
     *
     * @param  Closure(): T  $create
     * @return T
     *
     * @throws PlanLimitReached
     */
    public function within(LimitedResource $resource, Closure $create, int $adding = 1): mixed
    {
        if ($this->limit($resource) === null) {
            return $create();
        }

        return DB::transaction(function () use ($resource, $create, $adding) {
            Workspace::query()->whereKey($this->workspace->id)->lockForUpdate()->first();

            $this->ensureRoom($resource, $adding);

            return $create();
        });
    }

    /** @return array{plan: Plan, source: string, subscription: Subscription|null} */
    private function resolve(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        if (! $this->catalog->enabled()) {
            return $this->resolved = ['plan' => $this->catalog->unlimited(), 'source' => 'self-hosted', 'subscription' => null];
        }

        $free = $this->catalog->free();
        [$subscription, $bought] = $this->bestSubscription();
        $granted = $this->catalog->find($this->workspace->granted_plan);

        $plan = $free;
        $source = 'free';

        if ($granted !== null && $granted->rank > $plan->rank) {
            [$plan, $source] = [$granted, 'granted'];
        }

        // On a tie the purchase wins: it is the one with a bill attached.
        if ($bought !== null && $bought->rank >= $plan->rank && $bought->rank > $free->rank) {
            [$plan, $source] = [$bought, 'subscription'];
        }

        return $this->resolved = ['plan' => $plan, 'source' => $source, 'subscription' => $subscription];
    }

    /**
     * The best of the workspace's subscriptions that are still in force.
     * Filtered in PHP rather than with Cashier's `valid()` scope: a workspace
     * has one or two, and a scope full of `OR`s next to a `type` condition is
     * exactly where a query quietly starts matching someone else's rows.
     *
     * @return array{0: Subscription|null, 1: Plan|null}
     */
    private function bestSubscription(): array
    {
        $best = [null, null];

        $subscriptions = Cashier::$subscriptionModel::query()
            ->where('type', self::subscriptionType($this->workspace))
            ->with('items')
            ->orderBy('id')
            ->get();

        foreach ($subscriptions as $subscription) {
            if (! $subscription->valid()) {
                continue;
            }

            $plan = $subscription->items
                ->map(fn ($item) => $this->catalog->forPrice($item->price_id))
                ->filter()
                ->sortByDesc('rank')
                ->first();

            if ($plan !== null && ($best[1] === null || $plan->rank >= $best[1]->rank)) {
                $best = [$subscription, $plan];
            }
        }

        return $best;
    }
}
