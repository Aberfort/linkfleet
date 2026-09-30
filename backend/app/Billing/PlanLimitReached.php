<?php

namespace App\Billing;

use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A workspace tried to grow past what its plan allows. 402, with enough in the
 * body for a client to say what happened and where to go about it.
 *
 * Only ever thrown when something is being *added*. Reading, redirecting and
 * counting clicks never depend on a plan: a lapsed subscription must not take
 * a customer's live links down.
 */
class PlanLimitReached extends RuntimeException
{
    public function __construct(
        public readonly LimitedResource $resource,
        public readonly Plan $plan,
        public readonly int $limit,
        public readonly int $usage,
        public readonly Workspace $workspace,
    ) {
        parent::__construct(sprintf(
            'Ліміт плану «%s» вичерпано — %s: %d. Перейдіть на вищий план, щоб додати більше.',
            $plan->name,
            $resource->noun(),
            $limit,
        ));
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'plan_limit',
            'resource' => $this->resource->value,
            'limit' => $this->limit,
            'usage' => $this->usage,
            'plan' => $this->plan->key,
            'workspace_id' => $this->workspace->id,
        ], 402);
    }
}
