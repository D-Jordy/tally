<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dividends', function (Blueprint $table) {
            $table->boolean('confirmed')->default(false)->after('currency');
        });
    }

    public function down(): void
    {
        Schema::table('dividends', function (Blueprint $table) {
            $table->dropColumn('confirmed');
        });
    }
};
