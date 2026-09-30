<?php

namespace App\Http\Controllers;

use App\Billing\Entitlements;
use App\Billing\LimitedResource;
use App\Billing\PlanCatalog;
use App\Http\Requests\BillingPriceRequest;
use App\Models\User;
use App\Models\Workspace;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Paddle\Exceptions\PaddleException;
use Laravel\Paddle\Subscription;
use Throwable;

/**
 * A workspace's plan: what it is, what it uses, and how the owner changes it.
 *
 * Money is handled by Paddle and Cashier; this only decides who may ask for
 * what. The person who pays is a *user*, so changing or cancelling a plan
 * belongs to whoever paid for it - a second owner can see it, not end it.
 */
class BillingController extends Controller
{
    public function show(Request $request, Workspace $workspace): array
    {
        $this->authorize('view', $workspace);

        return $this->present($request->user(), $workspace);
    }

    /**
     * The options Paddle.js opens its checkout with. The customer is the
     * signed-in user and the subscription is filed under this workspace; both
     * are decided here, not by the browser.
     */
    public function checkout(BillingPriceRequest $request, Workspace $workspace): JsonResponse
    {
        $this->ensureConfigured();

        if (Entitlements::for($workspace)->subscription() !== null) {
            return response()->json([
                'message' => 'У цього workspace вже є підписка. Змініть план або скасуйте її замість нової оплати.',
            ], 409);
        }

        $checkout = $this->viaPaddle(fn () => $request->user()->subscribe(
            $request->validated('price_id'),
            Entitlements::subscriptionType($workspace),
        ));

        return response()->json(['checkout' => $checkout->options()]);
    }

    public function change(BillingPriceRequest $request, Workspace $workspace): array|JsonResponse
    {
        $subscription = $this->payersSubscription($request->user(), $workspace);

        if ($subscription->pastDue()) {
            return response()->json(['message' => 'Спершу оновіть спосіб оплати: остання оплата не пройшла.'], 409);
        }

        if ($subscription->hasPrice($request->validated('price_id'))) {
            return response()->json(['message' => 'Цей план уже підключено.'], 409);
        }

        $this->viaPaddle(fn () => $subscription->swap($request->validated('price_id')));

        return $this->present($request->user(), $workspace);
    }

    /** Ends at the close of the period already paid for, not today. */
    public function cancel(Request $request, Workspace $workspace): array
    {
        $this->authorize('manageBilling', $workspace);
        $subscription = $this->payersSubscription($request->user(), $workspace);

        if (! $subscription->onGracePeriod()) {
            $this->viaPaddle(fn () => $subscription->cancel());
        }

        return $this->present($request->user(), $workspace);
    }

    /** Takes back a cancellation that has not taken effect yet. */
    public function resume(Request $request, Workspace $workspace): array|JsonResponse
    {
        $this->authorize('manageBilling', $workspace);
        $subscription = $this->payersSubscription($request->user(), $workspace);

        if (! $subscription->onGracePeriod()) {
            return response()->json(['message' => 'Підписку не скасовано — відновлювати нічого.'], 409);
        }

        $this->viaPaddle(fn () => $subscription->stopCancelation());

        return $this->present($request->user(), $workspace);
    }

    /** Paddle's hosted page for changing the card on file. */
    public function paymentMethod(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorize('manageBilling', $workspace);
        $subscription = $this->payersSubscription($request->user(), $workspace);

        return response()->json(['url' => $this->viaPaddle(fn () => $subscription->paymentMethodUpdateUrl())]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(User $user, Workspace $workspace): array
    {
        $catalog = app(PlanCatalog::class);
        $entitlements = Entitlements::for($workspace);
        $plan = $entitlements->plan();
        $subscription = $entitlements->subscription();
        $canManage = $user->can('manageBilling', $workspace);

        $usage = [];
        $over = [];

        foreach (LimitedResource::cases() as $resource) {
            $usage[$resource->value] = $entitlements->usage($resource);
            $limit = $entitlements->limit($resource);

            // Reachable by downgrading: the extras stay and keep working, but
            // nothing more can be added until the workspace is back under.
            if ($limit !== null && $usage[$resource->value] > $limit) {
                $over[] = $resource->value;
            }
        }

        return [
            'plan' => ['key' => $plan->key, 'name' => $plan->name],
            'source' => $entitlements->source(),
            'limits' => $plan->limitsArray(),
            'usage' => $usage,
            'over_limit' => $over,
            'can_manage' => $canManage,
            'subscription' => $subscription === null ? null : $this->presentSubscription($user, $subscription, $catalog, $canManage),
        ];
    }

    /** @return array<string, mixed> */
    private function presentSubscription(User $user, Subscription $subscription, PlanCatalog $catalog, bool $canManage): array
    {
        $priceId = $subscription->items->pluck('price_id')->first();
        $plan = $catalog->forPrice($priceId);
        $isPayer = $this->isPayer($user, $subscription);

        return [
            'status' => $subscription->status,
            'plan' => $plan?->key,
            'price_id' => $priceId,
            'interval' => $plan === null ? null : (array_search($priceId, $plan->prices, true) ?: null),
            'ends_at' => $subscription->ends_at?->toIso8601String(),
            'on_grace_period' => $subscription->onGracePeriod(),
            'past_due' => $subscription->pastDue(),
            'is_payer' => $isPayer,
            // Who is paying is for the people who could otherwise wonder why
            // they cannot change it.
            'payer_name' => $canManage ? $subscription->billable?->name : null,
        ];
    }

    private function isPayer(User $user, Subscription $subscription): bool
    {
        return $subscription->billable_type === $user->getMorphClass()
            && (int) $subscription->billable_id === $user->id;
    }

    /**
     * The workspace's subscription, provided the caller is who pays for it.
     */
    private function payersSubscription(User $user, Workspace $workspace): Subscription
    {
        $this->authorize('manageBilling', $workspace);
        $this->ensureConfigured();

        $subscription = Entitlements::for($workspace)->subscription();

        abort_if($subscription === null, 404, 'У цього workspace немає активної підписки.');
        abort_unless($this->isPayer($user, $subscription), 403, 'Підписку оплачує інший користувач — лише він може її змінювати.');

        return $subscription;
    }

    private function ensureConfigured(): void
    {
        $missing = app(PlanCatalog::class)->missingSettings();

        abort_if($missing !== [], 503, 'Оплата ще не налаштована на цьому сервері.');
    }

    /**
     * Runs a call into Paddle and turns any way it can fail into a 502: the
     * customer did nothing wrong and should be told it is Paddle's side, not
     * shown a stack trace. The real cause is reported for the operator.
     *
     * @template T
     *
     * @param  Closure(): T  $call
     * @return T
     */
    private function viaPaddle(Closure $call): mixed
    {
        try {
            return $call();
        } catch (PaddleException $e) {
            report($e);

            abort(502, 'Paddle відхилив запит. Спробуйте ще раз або напишіть нам.');
        } catch (Throwable $e) {
            report($e);

            abort(502, 'Не вдалося зв’язатися з Paddle. Спробуйте ще раз за хвилину.');
        }
    }
}
