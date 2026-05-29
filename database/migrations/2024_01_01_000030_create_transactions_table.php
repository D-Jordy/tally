<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('instrument_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('executed_at');
            $table->string('type', 10); // buy | sell
            $table->decimal('quantity', 18, 8);
            $table->decimal('price', 18, 8);
            $table->string('price_currency', 10);
            $table->decimal('fee', 18, 8)->default(0);
            $table->string('trade_currency', 10);
            $table->decimal('fx_rate_to_eur', 18, 8)->nullable();
            $table->decimal('local_value', 18, 4)->nullable();
            $table->decimal('value_eur', 18, 4)->nullable();
            $table->decimal('total_eur', 18, 4)->nullable();
            $table->string('source', 20)->default('import'); // import | manual
            $table->string('external_id')->nullable()->unique(); // col-17 UUID from DEGIRO
            $table->timestamps();

            $table->index(['account_id', 'executed_at']);
            $table->index('instrument_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
