<?php

use App\Models\Transaction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
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
     *
     * Limit: a trade exported once with a UUID and once without hashes two
     * different ways and survives as two rows. Every duplicate this migration
     * targets has a blank UUID on both copies, so they do collapse.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('dedupe_hash', 64)->nullable();
        });

        $seen = [];
        $deleted = 0;

        Transaction::orderBy('id')
            ->cursor()
            ->each(function (Transaction $transaction) use (&$seen, &$deleted): void {
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
                    // Destructive and one-shot: leave a trace of what went.
                    Log::warning('Deleting duplicate transaction', [
                        'id' => $transaction->id,
                        'account_id' => $transaction->account_id,
                        'instrument_id' => $transaction->instrument_id,
                        'executed_at' => $transaction->executed_at->toDateTimeString(),
                        'total_eur' => $transaction->total_eur,
                    ]);

                    $transaction->delete();
                    $deleted++;

                    return;
                }

                $seen[$key] = true;
                $transaction->dedupe_hash = $hash;
                $transaction->saveQuietly();
            });

        Log::info('Transaction dedupe backfill done', [
            'kept' => count($seen),
            'deleted' => $deleted,
        ]);

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
