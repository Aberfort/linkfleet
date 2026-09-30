<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            // When Paddle says the newest event we applied happened. Paddle
            // does not promise to deliver events in order, and Cashier applies
            // whatever arrives last - so without this a late "active" could
            // undo a "canceled" that had already landed.
            $table->timestamp('paddle_event_at', 6)->nullable();
        });

        Schema::table('workspaces', function (Blueprint $table) {
            // A plan given rather than sold (see `php artisan billing:grant`):
            // the operator's own workspaces, a partner, a refund made good.
            $table->string('granted_plan', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn('granted_plan');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('paddle_event_at');
        });
    }
};
