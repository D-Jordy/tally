<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Stores: 1 foreign_currency = rate_to_eur EUR
        // Rule #2: always stored inverted so conversion is always multiply.
        Schema::create('fx_rates', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('currency', 10);
            $table->decimal('rate_to_eur', 18, 8); // 1 CURRENCY = X EUR
            $table->timestamps();

            $table->unique(['date', 'currency']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fx_rates');
    }
};
