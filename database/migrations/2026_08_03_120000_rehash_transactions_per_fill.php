<?php

use App\Models\Transaction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * The dedupe hash keyed on the broker UUID when present. A DEGIRO order id covers
 * every partial fill of that order, so a split execution collapsed onto one row:
 * the LGEN sale of 22-10-2025 lost 193 of its 386 shares. The hash now comes from
 * the trade fields plus the fee, which is what separates two fills of one order.
 *
 * Recompute every stored hash so a re-import matches the existing rows instead of
 * inserting a second copy, and drop the per-account external_id unique — several
 * rows legitimately share one order id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique(['account_id', 'external_id']);
            $table->dropUnique(['account_id', 'dedupe_hash']);
        });

        $seen = [];

        Transaction::withoutGlobalScopes()
            ->orderBy('id')
            ->cursor()
            ->each(function (Transaction $transaction) use (&$seen): void {
                $hash = Transaction::makeDedupeHash(
                    $transaction->executed_at,
                    $transaction->instrument_id,
                    (string) $transaction->type,
                    $transaction->quantity,
                    $transaction->price,
                    $transaction->fee ?? 0,
                );

                $key = $transaction->account_id.'|'.$hash;

                if (isset($seen[$key])) {
                    Log::info('Dropping duplicate transaction on rehash', [
                        'id' => $transaction->id,
                        'account_id' => $transaction->account_id,
                        'executed_at' => (string) $transaction->executed_at,
                        'instrument_id' => $transaction->instrument_id,
                        'type' => $transaction->type,
                        'quantity' => $transaction->quantity,
                    ]);

                    $transaction->delete();

                    return;
                }

                $seen[$key] = true;
                $transaction->dedupe_hash = $hash;
                $transaction->saveQuietly();
            });

        Schema::table('transactions', function (Blueprint $table) {
            $table->unique(['account_id', 'dedupe_hash']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->unique(['account_id', 'external_id']);
        });
    }
};
