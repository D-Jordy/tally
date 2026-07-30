<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instruments', function (Blueprint $table) {
            // The provider's own dividend yield as a ratio (0.048 = 4.8%). A ratio, not a
            // per-share rate: Yahoo reports LSE rates in GBP while quoting the price in
            // GBp, so anything with a unit needs currency guesswork. This does not.
            $table->decimal('dividend_yield', 10, 6)->nullable()->after('analyst_rating');
        });
    }

    public function down(): void
    {
        Schema::table('instruments', function (Blueprint $table) {
            $table->dropColumn('dividend_yield');
        });
    }
};
