<?php

namespace App\Http\Controllers;

use App\Billing\Entitlements;
use App\Billing\LimitedResource;
use App\Enums\WorkspaceRole;
use App\Http\Requests\AddMemberRequest;
use App\Http\Requests\UpdateMemberRequest;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WorkspaceMemberController extends Controller
{
    public function index(Workspace $workspace)
    {
        $this->authorize('view', $workspace);

        return $workspace->members()
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => $this->present($user));
    }

    /**
     * Adds an existing account by email. There is no invitation flow: a
     * person has to have registered first. That leaks whether an email has an
     * account to the workspace's owners, which is the accepted price of not
     * running a mail pipeline (the public demo has none, and registration is
     * closed there anyway).
     */
    public function store(AddMemberRequest $request, Workspace $workspace)
    {
        $user = User::where('email', $request->validated('email'))->first();

        if (! $user) {
            throw ValidationException::withMessages([
                'email' => 'Користувача з таким email не знайдено. Він має спершу зареєструватись.',
            ]);
        }

        if ($workspace->members()->whereKey($user->id)->exists()) {
            throw ValidationException::withMessages([
                'email' => 'Цей користувач уже є учасником workspace.',
            ]);
        }

        Entitlements::for($workspace)->within(
            LimitedResource::Members,
            fn () => $workspace->members()->attach($user->id, ['role' => $request->validated('role')]),
        );

        return response()->json($this->present($workspace->members()->find($user->id)), 201);
    }

    public function update(UpdateMemberRequest $request, Workspace $workspace, User $user)
    {
        $role = WorkspaceRole::from($request->validated('role'));

        $this->keepingAnOwner($workspace, $user, fn () => $workspace->members()->updateExistingPivot($user->id, [
            'role' => $role->value,
        ]), demoting: $role !== WorkspaceRole::Owner);

        return $this->present($workspace->members()->find($user->id));
    }

    /**
     * Owners remove others; anyone may remove themselves (leave). The leave
     * path asks the gate too, so the read-only demo account cannot walk out
     * of its own workspace.
     */
    public function destroy(Request $request, Workspace $workspace, User $user)
    {
        $this->authorize($request->user()->is($user) ? 'leave' : 'manageMembers', $workspace);

        $this->keepingAnOwner($workspace, $user, fn () => $workspace->members()->detach($user->id), demoting: true);

        return response()->noContent();
    }

    /**
     * Runs $change unless it would leave the workspace with no owner. The
     * workspace row is locked first, so two owners demoting each other at the
     * same moment cannot both pass the check.
     */
    private function keepingAnOwner(Workspace $workspace, User $target, callable $change, bool $demoting): void
    {
        DB::transaction(function () use ($workspace, $target, $change, $demoting) {
            Workspace::whereKey($workspace->id)->lockForUpdate()->first();

            $member = $workspace->members()->find($target->id);

            abort_if($member === null, 404);

            $isOwner = $member->pivot->role === WorkspaceRole::Owner;

            if ($demoting && $isOwner && $workspace->ownerCount() <= 1) {
                throw ValidationException::withMessages([
                    'role' => 'У workspace має лишитись хоча б один власник.',
                ]);
            }

            $change();
        });
    }

    /** @return array<string, mixed> */
    private function present(User $user): array
    {
        return [
            'user_id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->pivot->role->value,
            'joined_at' => $user->pivot->created_at,
        ];
    }
}
