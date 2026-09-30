<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clicks', function (Blueprint $table) {
            // What a destination site hands back to say "this visitor
            // converted". Random and unrelated to the visitor, so unlike the
            // ip_hash it identifies nothing but the click itself. Null for
            // clicks recorded before conversions existed.
            $table->string('token', 24)->nullable()->unique();
        });

        Schema::table('sites', function (Blueprint $table) {
            // Opt-in: turning it on makes redirects append ?lf_click=... to
            // the destination, which not every destination will welcome.
            $table->boolean('conversion_tracking')->default(false);
        });

        Schema::create('conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('click_id')->constrained()->cascadeOnDelete();
            // Copied from the click, as reports group by link and should not
            // have to join through clicks to do it.
            $table->foreignId('link_id')->constrained()->cascadeOnDelete();
            $table->string('event', 64);
            $table->decimal('value', 12, 2)->nullable();
            $table->char('currency', 3)->nullable();
            // '' when the caller gave none - not NULL. A unique index treats
            // every NULL as different, so a retried request with no id would
            // sail past the very constraint meant to stop the duplicate.
            $table->string('external_id', 128)->default('');
            // server (an authenticated API call) or pixel (from a browser,
            // which anyone holding the click token could send).
            $table->string('source', 10);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['click_id', 'event', 'external_id']);
            $table->index(['link_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversions');

        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('conversion_tracking');
        });

        Schema::table('clicks', function (Blueprint $table) {
            $table->dropUnique(['token']);
            $table->dropColumn('token');
        });
    }
};
