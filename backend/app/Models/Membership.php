<?php

namespace App\Models;

use App\Enums\WorkspaceRole;
use Illuminate\Database\Eloquent\Relations\Pivot;

/** The user_workspace row: one user's role in one workspace. */
class Membership extends Pivot
{
    protected $casts = [
        'role' => WorkspaceRole::class,
    ];
}
