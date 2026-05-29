<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Announced dividends per instrument (from market data provider).
        // Feeds the dividend calendar and income forecast.
        Schema::create('dividends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instrument_id')->constrained()->cascadeOnDelete();
            $table->date('ex_date');
            $table->date('pay_date')->nullable();
            $table->decimal('amount_per_share', 18, 8);
            $table->string('currency', 10);
            $table->timestamps();

            $table->unique(['instrument_id', 'ex_date']);
            $table->index('ex_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dividends');
    }
};
