<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('instrument_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampTz('occurred_at');
            $table->date('value_date')->nullable();
            // dividend | withholding_tax | fee | deposit | withdrawal
            // interest | fx_conversion | promo | internal | trade
            $table->string('type', 30);
            $table->decimal('amount', 18, 8);
            $table->string('currency', 10);
            $table->decimal('fx_rate', 18, 8)->nullable();
            $table->decimal('balance_eur', 18, 4)->nullable();
            $table->text('description')->nullable();
            $table->boolean('excluded_from_returns')->default(false);
            $table->string('source', 20)->default('import'); // import | manual
            // dedupe key for rows without a unique ID: hash of date+time+description+amount
            $table->string('dedupe_hash', 64)->nullable();
            $table->timestamps();

            $table->index(['account_id', 'occurred_at']);
            $table->index(['account_id', 'type']);
            $table->unique(['account_id', 'dedupe_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_movements');
    }
};
