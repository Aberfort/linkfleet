<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sites used to belong to a user; they now belong to a workspace the user is
 * a member of. Every existing user gets one workspace of their own and keeps
 * everything they had - the move is invisible to them.
 *
 * Runs on the live database at deploy time, so it uses the query builder
 * only: a model added or changed later must not be able to break it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->foreignId('workspace_id')->nullable()->constrained()->cascadeOnDelete();
        });

        $now = now();

        DB::table('users')->orderBy('id')->each(function ($user) use ($now) {
            $workspaceId = DB::table('workspaces')->insertGetId([
                'name' => 'Мій workspace',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('user_workspace')->insert([
                'workspace_id' => $workspaceId,
                'user_id' => $user->id,
                'role' => 'owner',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('sites')->where('user_id', $user->id)->update(['workspace_id' => $workspaceId]);
        });

        Schema::table('sites', function (Blueprint $table) {
            // The foreign key goes first: MySQL will not drop an index a
            // foreign key is still leaning on, and (user_id, name) is one.
            $table->dropForeign(['user_id']);
            $table->dropUnique(['user_id', 'name']);
            $table->dropColumn('user_id');
        });

        Schema::table('sites', function (Blueprint $table) {
            $table->foreignId('workspace_id')->nullable(false)->change();
            $table->unique(['workspace_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
        });

        // Back to a single owner per site: the workspace's earliest owner.
        DB::table('sites')->orderBy('id')->each(function ($site) {
            $ownerId = DB::table('user_workspace')
                ->where('workspace_id', $site->workspace_id)
                ->where('role', 'owner')
                ->orderBy('id')
                ->value('user_id');

            DB::table('sites')->where('id', $site->id)->update(['user_id' => $ownerId]);
        });

        Schema::table('sites', function (Blueprint $table) {
            $table->dropForeign(['workspace_id']);
            $table->dropUnique(['workspace_id', 'name']);
            $table->dropColumn('workspace_id');
        });

        Schema::table('sites', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable(false)->change();
            $table->unique(['user_id', 'name']);
        });

        // Workspaces mean nothing to the old schema. Leaving them behind
        // would make up() create a second set on the next run.
        DB::table('user_workspace')->delete();
        DB::table('workspaces')->delete();
    }
};
