<?php

namespace App\Services\Import;

use App\Models\Account;
use App\Models\CashMovement;
use App\Models\Instrument;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Imports the DEGIRO account/ledger CSV (cash movements file).
 *
 * Column layout (0-indexed):
 *   0  Datum           (DD-MM-YYYY)
 *   1  Tijd            (HH:MM)
 *   2  Valutadatum     (value date, DD-MM-YYYY)
 *   3  Product         (instrument name, often blank)
 *   4  ISIN            (often blank)
 *   5  Omschrijving    (description — drives the classifier)
 *   6  FX              (FX rate on the Valuta Debitering leg)
 *   7  Mutatie         (amount, Dutch decimal)
 *   8  (Mutatie currency — unlabelled)
 *   9  Saldo           (running balance)
 *  10  (Saldo currency — unlabelled)
 *  11  Order Id        (often blank)
 *
 * Dedupe: no reliable unique ID → hash(date + time + description + amount + currency)
 */
class AccountImporter
{
    private int $inserted = 0;
    private int $skipped  = 0;
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
                    Log::warning('AccountImporter row error', [
                        'account' => $account->id,
                        'line'    => $lineNumber,
                        'error'   => $e->getMessage(),
                    ]);
                }
            }
        });

        $this->updateWatermark($account, $rows);

        return new ImportResult($this->inserted, $this->skipped, $this->errors);
    }

    private function processRow(Account $account, array $row): void
    {
        if (count($row) < 8) {
            return;
        }

        $description = trim($row[5] ?? '');
        $isin        = trim($row[4] ?? '');
        $rawAmount   = $this->parseDecimal($row[7]);
        $currency    = trim($row[8] ?? '');

        if ($rawAmount === null || $currency === '') {
            return;
        }

        [$amount, $currency] = CurrencyNormaliser::normalise($rawAmount, $currency);

        $type     = $this->classify($description);
        $excluded = $this->isExcludedFromReturns($type);

        $dedupeHash = $this->dedupeHash(
            $row[0], $row[1], $description, $rawAmount, $row[8] ?? ''
        );

        // Idempotency: skip if this hash already exists for this account
        if (CashMovement::where('account_id', $account->id)
                        ->where('dedupe_hash', $dedupeHash)
                        ->exists()) {
            $this->skipped++;
            return;
        }

        $instrument = null;
        if ($isin !== '') {
            $name = trim($row[3] ?? '');
            $instrument = Instrument::firstOrCreate(
                ['isin' => $isin],
                ['name' => $name ?: $isin]
            );
        }

        $occurredAt = $this->parseDateTime($row[0], $row[1]);
        $valueDate  = $this->parseDate($row[2]);
        $fxRate     = $this->parseDecimal($row[6]);
        $balanceEur = $this->parseBalanceEur($row[9], $row[10] ?? '');

        CashMovement::create([
            'account_id'              => $account->id,
            'instrument_id'           => $instrument?->id,
            'occurred_at'             => $occurredAt,
            'value_date'              => $valueDate,
            'type'                    => $type,
            'amount'                  => $amount,
            'currency'                => $currency,
            'fx_rate'                 => $fxRate ?: null,
            'balance_eur'             => $balanceEur,
            'description'             => $description,
            'excluded_from_returns'   => $excluded,
            'source'                  => 'import',
            'dedupe_hash'             => $dedupeHash,
        ]);

        $this->inserted++;
    }

    /**
     * Classify a row by its Omschrijving (description) string.
     *
     * Types:
     *   trade            — mirror of the transactions file; stored for audit, not double-counted
     *   dividend         — gross dividend or coupon/ETF distribution
     *   withholding_tax  — Dividendbelasting (negative amount)
     *   fee              — transaction costs, stamp duty, exchange fees, tax on trade
     *   deposit          — iDEAL Deposit (money in)
     *   withdrawal       — SEPA / flatex withdrawal (money out)
     *   interest         — Flatex interest income / Rente
     *   fx_conversion    — Valuta Creditering / Valuta Debitering pair
     *   promo            — DEGIRO promo credit
     *   internal         — cash sweeps, iDEAL reservation (noise; excluded from returns)
     */
    private function classify(string $description): string
    {
        $d = mb_strtolower($description);

        // Trade mirrors (in both files — stored for audit only)
        if (str_starts_with($d, 'koop ') || str_starts_with($d, 'verkoop ')) {
            return 'trade';
        }

        // Dividends
        if (str_contains($d, 'dividendbelasting')) {
            return 'withholding_tax';
        }
        if (str_contains($d, 'dividend') || str_contains($d, 'coupon')) {
            return 'dividend';
        }
        if (str_contains($d, 'inkomsten uit securities lending')) {
            return 'dividend'; // modelled as income
        }

        // Fees / taxes
        if (str_contains($d, 'transactiekosten') || str_contains($d, 'transactie kosten')) {
            return 'fee';
        }
        if (str_contains($d, 'stamp duty') || str_contains($d, 'stampduty')) {
            return 'fee';
        }
        if (str_contains($d, 'transaction tax') || str_contains($d, 'taks')) {
            return 'fee';
        }
        if (str_contains($d, 'aansluitingskosten')) {
            return 'fee';
        }
        if (str_contains($d, 'kosten')) {
            return 'fee';
        }

        // FX conversions
        if (str_contains($d, 'valuta creditering') || str_contains($d, 'valuta debitering')) {
            return 'fx_conversion';
        }

        // Deposits
        if (str_contains($d, 'ideal deposit') || str_contains($d, 'ideal storting') || str_contains($d, 'ideal-storting')) {
            return 'deposit';
        }

        // Withdrawals
        if (str_contains($d, 'terugstorting') || str_contains($d, 'withdrawal') || str_contains($d, 'teruggave')) {
            return 'withdrawal';
        }

        // Internal noise
        if (str_contains($d, 'reservation') || str_contains($d, 'reservering')) {
            return 'internal';
        }
        if (str_contains($d, 'cash sweep') || str_contains($d, 'overboeking') || str_contains($d, 'flatex')) {
            return 'internal';
        }

        // Interest
        if (str_contains($d, 'rente') || str_contains($d, 'interest income') || str_contains($d, 'interest')) {
            return 'interest';
        }

        // Promo
        if (str_contains($d, 'promotie') || str_contains($d, 'promo')) {
            return 'promo';
        }

        return 'other';
    }

    /**
     * Rows that should not affect XIRR / cashflow calculations.
     * Internal noise and trade mirrors are both excluded.
     */
    private function isExcludedFromReturns(string $type): bool
    {
        return in_array($type, ['internal', 'trade'], true);
    }

    private function dedupeHash(
        string $date,
        string $time,
        string $description,
        ?string $amount,
        string $currency
    ): string {
        return hash('sha256', implode('|', [
            trim($date),
            trim($time),
            trim($description),
            trim((string) $amount),
            trim($currency),
        ]));
    }

    private function parseDateTime(string $date, string $time): Carbon
    {
        return Carbon::createFromFormat('d-m-Y H:i', trim($date) . ' ' . trim($time));
    }

    private function parseDate(string $value): ?string
    {
        $v = trim($value);
        if ($v === '') {
            return null;
        }
        try {
            return Carbon::createFromFormat('d-m-Y', $v)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseDecimal(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        return str_replace(',', '.', trim((string) $value));
    }

    private function parseBalanceEur(mixed $balance, string $currency): ?string
    {
        if (trim((string) $balance) === '') {
            return null;
        }
        $normalised = strtoupper(CurrencyNormaliser::normaliseCurrency(trim($currency)));
        if ($normalised !== 'EUR') {
            return null; // only store EUR balances
        }
        return $this->parseDecimal($balance);
    }

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
            if (array_filter($row, fn($v) => trim($v) !== '') === []) {
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
                // ignore
            }
        }

        if ($latest && ($account->import_watermark === null || $latest > $account->import_watermark->toDateString())) {
            $account->update(['import_watermark' => $latest]);
        }
    }
}
