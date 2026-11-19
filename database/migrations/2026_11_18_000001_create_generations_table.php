<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->id();
            // Our own id: sent as Idempotency-Key and as metadata.app_generation_id.
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Pixelforge id (gen_...). Null until Pixelforge accepted the request.
            $table->string('provider_id')->nullable()->unique();

            // What the user asked for
            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->json('reference_images');

            // pending | queued | processing | succeeded | failed | rejected
            $table->string('status')->default('pending')->index();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('submit_attempts')->default(0);

            // Money is stored as integer nanodollars (1 USD = 1_000_000_000).
            $table->bigInteger('estimated_min_nano');
            $table->bigInteger('estimated_max_nano');
            $table->json('rates')->nullable();   // frozen rates returned by Pixelforge
            $table->json('usage')->nullable();   // final token usage
            $table->bigInteger('provider_cost_nano')->nullable(); // what Pixelforge bills us
            $table->bigInteger('price_nano')->nullable();         // what the user pays (cost + markup), 0 if failed

            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();
            $table->timestamp('provider_created_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable()->index(); // Pixelforge bills on this UTC day
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
