<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instruments', function (Blueprint $table) {
            $table->id();
            $table->string('isin', 12)->unique();
            $table->string('name');
            $table->string('symbol')->nullable();
            $table->string('yahoo_symbol')->nullable();
            $table->string('quote_currency', 10)->nullable();
            $table->string('sector')->nullable();
            $table->string('country', 2)->nullable();
            $table->string('exchange')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instruments');
    }
};
