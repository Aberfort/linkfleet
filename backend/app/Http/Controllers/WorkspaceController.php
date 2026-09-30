<?php

namespace App\Http\Controllers;

use App\Billing\Entitlements;
use App\Enums\WorkspaceRole;
use App\Http\Requests\StoreWorkspaceRequest;
use App\Http\Requests\UpdateWorkspaceRequest;
use App\Models\Workspace;
use Illuminate\Http\Request;

class WorkspaceController extends Controller
{
    public function index(Request $request)
    {
        $roles = $request->user()->workspaceRoles();

        return Workspace::query()
            ->whereIn('id', array_keys($roles))
            ->withCount(['members', 'sites'])
            ->orderBy('name')
            ->get()
            ->each(fn (Workspace $workspace) => $workspace->setAttribute('role', $roles[$workspace->id]->value));
    }

    public function store(StoreWorkspaceRequest $request)
    {
        $workspace = Workspace::create($request->validated());
        $workspace->members()->attach($request->user()->id, ['role' => WorkspaceRole::Owner->value]);

        return response()->json(
            $workspace->loadCount(['members', 'sites'])->setAttribute('role', WorkspaceRole::Owner->value),
            201
        );
    }

    public function show(Request $request, Workspace $workspace)
    {
        $this->authorize('view', $workspace);

        return $workspace->loadCount(['members', 'sites'])
            ->setAttribute('role', $request->user()->roleIn($workspace)->value);
    }

    public function update(UpdateWorkspaceRequest $request, Workspace $workspace)
    {
        $workspace->update($request->validated());

        return $workspace->loadCount(['members', 'sites'])
            ->setAttribute('role', $request->user()->roleIn($workspace)->value);
    }

    public function destroy(Workspace $workspace)
    {
        $this->authorize('delete', $workspace);

        // Deleting the workspace would leave its subscription billing a card
        // for something that no longer exists. One that is already set to end
        // is fine - it costs nothing more.
        $subscription = Entitlements::for($workspace)->subscription();

        if ($subscription !== null && ! $subscription->onGracePeriod()) {
            return response()->json([
                'message' => 'Спершу скасуйте підписку цього workspace — інакше вона продовжуватиме списувати гроші.',
            ], 409);
        }

        $workspace->delete();

        return response()->noContent();
    }
}
