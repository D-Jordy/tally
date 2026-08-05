<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dividends', function (Blueprint $table) {
            // Cadence-projected payments: our own guess, not a provider fact.
            // Rebuilt per instrument by ProjectDividends after every dividend sync.
            $table->boolean('projected')->default(false)->after('confirmed');
        });
    }

    public function down(): void
    {
        Schema::table('dividends', function (Blueprint $table) {
            $table->dropColumn('projected');
        });
    }
};
