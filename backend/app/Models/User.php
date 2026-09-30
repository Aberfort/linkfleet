<?php

namespace App\Models;

use App\Enums\WorkspaceRole;
use App\Support\ApiKeyScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Paddle\Billable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    // Billable makes the *user* the Paddle customer: a person pays, however
    // many workspaces they pay for (see Billing\Entitlements::subscriptionType).
    use Billable, HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * is_demo is deliberately NOT listed here - it must only ever be set
     * by DemoUserSeeder, never via a registration payload.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'is_demo' => 'boolean',
    ];

    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class)
            ->using(Membership::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * Every workspace this user belongs to, as id => role. The single place
     * that answers "what may this user reach?" - policies and list endpoints
     * both go through it, so they cannot drift apart. A pinned API key narrows
     * it to its own workspace.
     *
     * @return array<int, WorkspaceRole>
     */
    public function workspaceRoles(): array
    {
        $roles = $this->workspaces()->get()
            ->mapWithKeys(fn (Workspace $workspace) => [$workspace->id => $workspace->pivot->role])
            ->all();

        // An API key pinned to one workspace sees only that one. Doing it
        // here, where every policy and list endpoint already looks, means no
        // resource can forget to honour the pin.
        $pinned = ApiKeyScope::workspaceId($this->currentAccessToken());

        return $pinned === null ? $roles : array_intersect_key($roles, [$pinned => true]);
    }

    /** Null when the user is not a member (or the workspace does not exist). */
    public function roleIn(Workspace|int $workspace): ?WorkspaceRole
    {
        return $this->workspaceRoles()[$workspace instanceof Workspace ? $workspace->id : $workspace] ?? null;
    }

    /**
     * The workspace a user lands in: the first one they own, created on
     * demand. Registration, the demo seeder and test factories all rely on
     * this so nobody ever ends up with nowhere to put a site.
     */
    public function defaultWorkspace(): Workspace
    {
        $owned = $this->workspaces()
            ->wherePivot('role', WorkspaceRole::Owner->value)
            ->orderBy('workspaces.id')
            ->first();

        if ($owned) {
            return $owned;
        }

        $workspace = Workspace::create(['name' => 'Мій workspace']);
        $workspace->members()->attach($this->id, ['role' => WorkspaceRole::Owner->value]);

        return $workspace;
    }
}
