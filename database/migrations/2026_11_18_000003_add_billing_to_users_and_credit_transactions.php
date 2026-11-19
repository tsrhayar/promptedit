<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Cached balance, always updated in the same transaction as the ledger row.
            $table->bigInteger('balance_nano')->default(0);
            $table->boolean('is_admin')->default(false);
        });

        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('generation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type'); // purchase | hold | release | charge
            $table->bigInteger('amount_nano'); // + credit, - debit
            $table->timestamps();

            // A generation can be held, released and charged at most once each.
            $table->unique(['generation_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['balance_nano', 'is_admin']);
        });
    }
};
