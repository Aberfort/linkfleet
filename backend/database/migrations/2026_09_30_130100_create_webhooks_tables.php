<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('url', 2048);
            // Encrypted at rest (see the model cast): unlike an API key it
            // has to be readable again, because signing needs the original.
            $table->text('secret');
            // ["link.created", "link.clicked", ...] - see App\Enums\WebhookEvent.
            $table->json('events');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webhook_id')->constrained()->cascadeOnDelete();
            $table->string('event', 64);
            $table->json('payload');
            // Null when nothing came back at all (blocked target, timeout, refused).
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->boolean('success');
            $table->string('error', 500)->nullable();
            $table->text('response_excerpt')->nullable();
            $table->unsignedTinyInteger('attempt')->default(1);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['webhook_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhooks');
    }
};
