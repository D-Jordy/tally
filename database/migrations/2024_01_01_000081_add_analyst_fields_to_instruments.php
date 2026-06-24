<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instruments', function (Blueprint $table) {
            $table->decimal('analyst_target_price', 20, 8)->nullable()->after('exchange');
            $table->string('analyst_rating', 50)->nullable()->after('analyst_target_price');
        });
    }

    public function down(): void
    {
        Schema::table('instruments', function (Blueprint $table) {
            $table->dropColumn(['analyst_target_price', 'analyst_rating']);
        });
    }
};
