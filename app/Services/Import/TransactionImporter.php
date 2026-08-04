<?php

namespace App\Services\Import;

use App\Models\Account;
use App\Models\Instrument;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Imports the DEGIRO transactions CSV (the trades file).
 *
 * Column layout (0-indexed, Dutch locale):
 *   0  Datum           (DD-MM-YYYY)
 *   1  Tijd            (HH:MM)
 *   2  Product         (name)
 *   3  ISIN
 *   4  Beurs
 *   5  Uitvoeringsplaats
 *   6  Aantal          (qty; NEGATIVE = sell)
 *   7  Koers           (price value)
 *   8  (price currency — unlabelled)
 *   9  Lokale waarde
 *  10  (local value currency — unlabelled)
 *  11  Waarde EUR
 *  12  Wisselkoers
 *  13  AutoFX Kosten
 *  14  Transactiekosten ... EUR
 *  15  Totaal EUR
 *  16  Order ID        (the UUID, unless DEGIRO emitted a blank column here)
 *  17  (the UUID when column 16 is blank)
 *
 * One order id covers every partial fill of that order, so it is stored but is
 * not the idempotency key — see Transaction::makeDedupeHash().
 */
class TransactionImporter
{
    private int $inserted = 0;

    private int $skipped = 0;

    private array $errors = [];

    public function import(Account $account, string $csvPath): ImportResult
    {
        $rows = $this->parseCsv($csvPath);

        DB::transaction(function () use ($account, $rows) {
            foreach ($rows as $lineNumber => $row) {
                try {
                    $this->processRow($account, $row);
                } catch (\Throwable $e) {
                    $this->errors[] = "Line {$lineNumber}: {$e->getMessage()}";
                    Log::warning('TransactionImporter row error', [
                        'account' => $account->id,
                        'line' => $lineNumber,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });

        $this->updateWatermark($account, $rows);

        return new ImportResult($this->inserted, $this->skipped, $this->errors);
    }

    private function processRow(Account $account, array $row): void
    {
        if (count($row) < 16) {
            return;
        }

        // The UUID is the last column, but DEGIRO only emits the (blank) Order ID
        // column on some rows, so it lands on index 17 or 16 depending on the row.
        $externalId = trim($row[17] ?? '') ?: trim($row[16] ?? '');
        $isin = trim($row[3] ?? '');

        if (empty($isin)) {
            return;
        }

        $instrument = $this->resolveInstrument($row);
        $executedAt = $this->parseDateTime($row[0], $row[1]);

        [$price, $priceCurrency] = CurrencyNormaliser::normalise(
            $this->parseDecimal($row[7]),
            trim($row[8] ?? '')
        );

        [$localValue, $localCurrency] = CurrencyNormaliser::normalise(
            $this->parseDecimal($row[9]),
            trim($row[10] ?? '')
        );

        $qty = $this->parseDecimal($row[6]);
        $type = (float) $qty >= 0 ? 'buy' : 'sell';
        $qty = abs((float) $qty);

        $valueEur = $this->parseDecimal($row[11]);
        $fxRate = $this->parseDecimal($row[12]);       // blank when already EUR
        $fee = $this->parseDecimal($row[14]);
        $totalEur = $this->parseDecimal($row[15]);

        $dedupeHash = Transaction::makeDedupeHash(
            $executedAt, $instrument->id, $type, $qty, $price, $fee ?? 0
        );

        // Idempotent: match on (account_id, dedupe_hash) so re-importing an
        // overlapping export updates the existing row instead of duplicating.
        $transaction = Transaction::firstOrNew([
            'account_id' => $account->id,
            'dedupe_hash' => $dedupeHash,
        ]);

        // A row corrected by hand outranks the CSV — re-importing the same export
        // must not silently undo the correction.
        if ($transaction->exists && $transaction->source === 'manual') {
            $this->skipped++;

            return;
        }

        $transaction->fill([
            'instrument_id' => $instrument->id,
            'executed_at' => $executedAt,
            'type' => $type,
            'quantity' => $qty,
            'price' => $price,
            'price_currency' => $priceCurrency,
            'fee' => $fee ?? 0,
            'trade_currency' => $localCurrency ?: $priceCurrency,
            'fx_rate_to_eur' => $fxRate ?: null,
            'local_value' => $localValue,
            'value_eur' => $valueEur,
            'total_eur' => $totalEur,
            'source' => 'import',
            'external_id' => $externalId ?: null,
        ])->save();

        $transaction->wasRecentlyCreated ? $this->inserted++ : $this->skipped++;
    }

    private function resolveInstrument(array $row): Instrument
    {
        $isin = trim($row[3]);
        $name = trim($row[2]);

        return Instrument::firstOrCreate(
            ['isin' => $isin],
            [
                'name' => $name,
                'exchange' => trim($row[4] ?? ''),
            ]
        );
    }

    private function parseDateTime(string $date, string $time): Carbon
    {
        // Dutch format: DD-MM-YYYY HH:MM
        return Carbon::createFromFormat('d-m-Y H:i', trim($date).' '.trim($time));
    }

    /**
     * Parse a Dutch-locale decimal string (comma as decimal separator, no thousands sep).
     * Quoted amounts like "-7588,00" are handled by CSV parser stripping quotes.
     */
    private function parseDecimal(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return str_replace(',', '.', trim((string) $value));
    }

    /**
     * Parse the DEGIRO CSV.
     * - Encoding: UTF-8
     * - Delimiter: comma
     * - Enclosure: double-quote
     * - Skip the header row
     * - Blank column names at indices 8, 10, 17 are normal — do NOT skip them
     */
    private function parseCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open CSV: {$path}");
        }

        $rows = [];
        $lineNumber = 0;

        while (($row = fgetcsv($handle, 0, ',', '"')) !== false) {
            $lineNumber++;
            if ($lineNumber === 1) {
                continue; // skip header
            }
            if (array_filter($row, fn ($v) => trim($v) !== '') === []) {
                continue; // skip blank lines
            }
            $rows[$lineNumber] = $row;
        }

        fclose($handle);

        return $rows;
    }

    private function updateWatermark(Account $account, array $rows): void
    {
        $latest = null;

        foreach ($rows as $row) {
            if (empty($row[0])) {
                continue;
            }
            try {
                $date = Carbon::createFromFormat('d-m-Y', trim($row[0]))->toDateString();
                if ($latest === null || $date > $latest) {
                    $latest = $date;
                }
            } catch (\Throwable) {
                // ignore unparseable dates
            }
        }

        if ($latest && ($account->import_watermark === null || $latest > $account->import_watermark->toDateString())) {
            $account->update(['import_watermark' => $latest]);
        }
    }
}
