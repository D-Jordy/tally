<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Transactions were deduped on the broker UUID alone. DEGIRO leaves that
     * column blank on some rows, so those re-imported as fresh trades every time.
     * Add the stable hash cash_movements already uses, and scope the external_id
     * unique per account: it was global, so a second user importing the same
     * export would collide.
     *
     * The backfill that lived here hashed on the broker UUID, which turned out to
     * collapse the partial fills of one order. It is gone; the follow-up migration
     * (rehash_transactions_per_fill) fills the column in for every row instead.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('dedupe_hash', 64)->nullable();
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique(['external_id']);
            $table->unique(['account_id', 'external_id']);
            $table->unique(['account_id', 'dedupe_hash']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique(['account_id', 'dedupe_hash']);
            $table->dropUnique(['account_id', 'external_id']);
            $table->unique('external_id');
            $table->dropColumn('dedupe_hash');
        });
    }
};
