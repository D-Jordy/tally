<?php

use App\Models\Transaction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Transactions were deduped on the broker UUID alone. DEGIRO leaves that
     * column blank on some rows, so those re-imported as fresh trades every time.
     * Add the same stable hash cash_movements already uses, backfill it, and drop
     * rows that collapse to the same (account_id, hash) — those are duplicates
     * from an earlier re-import. Also scope the external_id unique per account:
     * it was global, so a second user importing the same export would collide.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('dedupe_hash', 64)->nullable()->after('external_id');
        });

        $seen = [];

        Transaction::orderBy('id')
            ->cursor()
            ->each(function (Transaction $transaction) use (&$seen): void {
                $hash = Transaction::makeDedupeHash(
                    $transaction->external_id,
                    $transaction->executed_at,
                    $transaction->instrument_id,
                    (string) $transaction->type,
                    $transaction->quantity,
                    $transaction->price,
                );

                $key = $transaction->account_id.'|'.$hash;

                if (isset($seen[$key])) {
                    $transaction->delete();

                    return;
                }

                $seen[$key] = true;
                $transaction->dedupe_hash = $hash;
                $transaction->saveQuietly();
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
