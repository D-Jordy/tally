<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\CashMovement;
use App\Models\Dividend;
use App\Models\FxRate;
use App\Models\Instrument;
use App\Models\PriceHistory;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Builds a realistic demo portfolio for a single user (default: the
 * laury73 factory leftover). Idempotent — wipes the user's existing
 * accounts/instruments first, then rebuilds prices, FX, trades,
 * dividends and cash movements.
 *
 *   php artisan db:seed --class=DemoSeeder
 */
class DemoSeeder extends Seeder
{
    private string $email = 'laury73@example.com';

    public function run(): void
    {
        $user = User::where('email', $this->email)->first()
            ?? User::factory()->create(['email' => $this->email]);

        // Clean slate (instruments/prices are global, so reset them too).
        Account::where('user_id', $user->id)->delete();
        Instrument::query()->delete();
        FxRate::query()->delete();

        $user->settings = [...$user->settings ?? [], 'annual_contribution_eur' => 6000];
        $user->save();

        $account = Account::create([
            'user_id' => $user->id,
            'broker' => 'degiro',
            'name' => 'DEGIRO Beleggingsrekening',
            'import_watermark' => now()->subDays(2),
        ]);

        $this->seedFx();

        $today = Carbon::today();
        $totalInvested = 0.0;
        $externalId = 1;

        foreach ($this->holdings() as $config) {
            $instrument = Instrument::create([
                'isin' => $config['isin'],
                'name' => $config['name'],
                'symbol' => $config['symbol'],
                'yahoo_symbol' => $config['symbol'],
                'quote_currency' => $config['currency'],
                'sector' => $config['sector'],
                'country' => $config['country'],
                'exchange' => $config['currency'] === 'USD' ? 'NDQ' : 'EAM',
                'analyst_target_price' => $config['target'],
                'analyst_rating' => $config['target'] ? 'buy' : null,
            ]);

            $boughtAt = $today->copy()->subWeeks($config['weeks']);
            $this->seedPrices($instrument, $config, $boughtAt, $today);

            $localValue = round($config['qty'] * $config['buy'], 2);
            $fee = $config['currency'] === 'USD' ? 1.0 : 2.0;
            $fxRate = $config['currency'] === 'USD' ? 0.92 : 1.0;
            $valueEur = round($localValue * $fxRate, 2);
            $totalEur = round($valueEur + $fee, 2);
            $totalInvested += $totalEur;

            Transaction::create([
                'account_id' => $account->id,
                'instrument_id' => $instrument->id,
                'executed_at' => $boughtAt->copy()->addHours(10),
                'type' => 'buy',
                'quantity' => $config['qty'],
                'price' => $config['buy'],
                'price_currency' => $config['currency'],
                'fee' => $fee,
                'trade_currency' => $config['currency'],
                'fx_rate_to_eur' => $config['currency'] === 'USD' ? $fxRate : null,
                'local_value' => $localValue,
                'value_eur' => $valueEur,
                'total_eur' => $totalEur,
                'source' => 'import',
                'external_id' => 'demo-'.$externalId++,
            ]);

            $this->seedDividends($account, $instrument, $config, $today);
        }

        // A few closed round-trips so realised P&L is non-zero (one winner, one
        // loser). Their cash flows net out roughly, so they are not added to the
        // deposit base — net gain stays driven by the open holdings.
        $this->seedClosedTrade($account, [
            'isin' => 'NL0011794037', 'name' => 'Ahold Delhaize', 'symbol' => 'AD', 'currency' => 'EUR', 'sector' => 'Consumer Staples', 'country' => 'NL',
            'qty' => 80, 'buy' => 26.0, 'sell' => 33.0, 'buyWeeks' => 88, 'sellWeeks' => 22,
        ], $externalId);
        $this->seedClosedTrade($account, [
            'isin' => 'US09075V1026', 'name' => 'BioNTech SE', 'symbol' => 'BNTX', 'currency' => 'USD', 'sector' => 'Healthcare', 'country' => 'DE',
            'qty' => 15, 'buy' => 108.0, 'sell' => 94.0, 'buyWeeks' => 76, 'sellWeeks' => 30,
        ], $externalId);

        // Deposits match the deployed capital, spread over a few stortingen, so
        // net gain reflects appreciation rather than idle cash.
        $this->deposit($account, round($totalInvested * 0.6, 2), $today->copy()->subWeeks(82));
        $this->deposit($account, round($totalInvested * 0.4, 2), $today->copy()->subWeeks(50));

        // Broker connection fees (per exchange, per year) net of an intro rebate.
        foreach ([72, 60, 24, 12] as $weeksAgo) {
            $this->fee($account, -2.5, $today->copy()->subWeeks($weeksAgo), 'Aansluitkosten beurs');
        }
        CashMovement::create([
            'account_id' => $account->id, 'occurred_at' => $today->copy()->subWeeks(80),
            'type' => 'promo', 'amount' => 2.5, 'currency' => 'EUR', 'description' => 'Introductiekorting',
            'source' => 'import',
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function holdings(): array
    {
        return [
            ['isin' => 'NL0010273215', 'name' => 'ASML Holding', 'symbol' => 'ASML', 'currency' => 'EUR', 'sector' => 'Technology', 'country' => 'NL', 'qty' => 8, 'buy' => 580.0, 'current' => 690.0, 'weeks' => 78, 'target' => 820.0, 'div' => null],
            ['isin' => 'GB00BP6MXD84', 'name' => 'Shell plc', 'symbol' => 'SHELL', 'currency' => 'EUR', 'sector' => 'Energy', 'country' => 'GB', 'qty' => 90, 'buy' => 26.5, 'current' => 31.2, 'weeks' => 70, 'target' => 35.0, 'div' => ['amount' => 0.33, 'cadence' => 13, 'confirmed' => true]],
            ['isin' => 'NL0011872643', 'name' => 'ASR Nederland', 'symbol' => 'ASRNL', 'currency' => 'EUR', 'sector' => 'Insurance', 'country' => 'NL', 'qty' => 60, 'buy' => 41.0, 'current' => 47.8, 'weeks' => 64, 'target' => null, 'div' => ['amount' => 1.05, 'cadence' => 26, 'confirmed' => true]],
            ['isin' => 'US0378331005', 'name' => 'Apple Inc.', 'symbol' => 'AAPL', 'currency' => 'USD', 'sector' => 'Technology', 'country' => 'US', 'qty' => 25, 'buy' => 165.0, 'current' => 212.0, 'weeks' => 82, 'target' => 240.0, 'div' => ['amount' => 0.25, 'cadence' => 13, 'confirmed' => false]],
            ['isin' => 'US7561091049', 'name' => 'Realty Income', 'symbol' => 'O', 'currency' => 'USD', 'sector' => 'Real Estate', 'country' => 'US', 'qty' => 70, 'buy' => 58.0, 'current' => 56.5, 'weeks' => 56, 'target' => 64.0, 'div' => ['amount' => 0.26, 'cadence' => 4, 'confirmed' => true]],
            ['isin' => 'US5949181045', 'name' => 'Microsoft Corp.', 'symbol' => 'MSFT', 'currency' => 'USD', 'sector' => 'Technology', 'country' => 'US', 'qty' => 12, 'buy' => 330.0, 'current' => 430.0, 'weeks' => 48, 'target' => 480.0, 'div' => ['amount' => 0.75, 'cadence' => 13, 'confirmed' => false]],
        ];
    }

    private function seedFx(): void
    {
        $today = Carbon::today();

        for ($week = 90; $week >= 0; $week--) {
            $date = $today->copy()->subWeeks($week);
            $rate = 0.92 + 0.03 * sin($week / 7);
            FxRate::create(['date' => $date, 'currency' => 'USD', 'rate_to_eur' => round($rate, 6)]);
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function seedPrices(Instrument $instrument, array $config, Carbon $from, Carbon $to): void
    {
        $weeks = max(1, $from->diffInWeeks($to));
        $buy = (float) $config['buy'];
        $current = (float) $config['current'];

        for ($step = 0; $step <= $weeks; $step++) {
            $progress = $step / $weeks;
            $trend = $buy + ($current - $buy) * $progress;
            $noise = $trend * 0.04 * sin($step / 3 + crc32($config['symbol']) % 7);
            $close = max(0.5, round($trend + $noise, 4));

            PriceHistory::create([
                'instrument_id' => $instrument->id,
                'date' => $from->copy()->addWeeks($step),
                'close' => $step === $weeks ? $current : $close,
                'currency' => $config['currency'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function seedDividends(Account $account, Instrument $instrument, array $config, Carbon $today): void
    {
        if ($config['div'] === null) {
            return;
        }

        $amount = (float) $config['div']['amount'];
        $cadence = (int) $config['div']['cadence']; // weeks between pay-outs

        // Historical ex-dates + received cash (last ~14 months), oldest first.
        for ($occurrence = 1; $occurrence <= 6; $occurrence++) {
            $exDate = $today->copy()->subWeeks($cadence * $occurrence);

            Dividend::create([
                'instrument_id' => $instrument->id,
                'ex_date' => $exDate,
                'pay_date' => $exDate->copy()->addWeeks(2),
                'amount_per_share' => $amount,
                'currency' => $config['currency'],
                'confirmed' => true,
            ]);

            if ($exDate->greaterThan($today->copy()->subYear())) {
                CashMovement::create([
                    'account_id' => $account->id,
                    'instrument_id' => $instrument->id,
                    'occurred_at' => $exDate->copy()->addWeeks(2),
                    'type' => 'dividend',
                    'amount' => round($amount * $config['qty'], 2),
                    'currency' => $config['currency'],
                    'description' => 'Dividend '.$instrument->name,
                    'source' => 'import',
                ]);
            }
        }

        // Confirmed upcoming ex-date for some holdings.
        if ($config['div']['confirmed']) {
            Dividend::create([
                'instrument_id' => $instrument->id,
                'ex_date' => $today->copy()->addWeeks((int) ($cadence / 2) + 1),
                'pay_date' => $today->copy()->addWeeks((int) ($cadence / 2) + 3),
                'amount_per_share' => $amount,
                'currency' => $config['currency'],
                'confirmed' => true,
            ]);
        }
    }

    /**
     * Seed a fully-closed buy→sell round-trip. Returns the buy cost in EUR so
     * the caller can fund it from deposits.
     *
     * @param  array<string, mixed>  $config
     */
    private function seedClosedTrade(Account $account, array $config, int &$externalId): float
    {
        $today = Carbon::today();
        $fxRate = $config['currency'] === 'USD' ? 0.92 : 1.0;
        $fee = $config['currency'] === 'USD' ? 1.0 : 2.0;

        $instrument = Instrument::create([
            'isin' => $config['isin'],
            'name' => $config['name'],
            'symbol' => $config['symbol'],
            'yahoo_symbol' => $config['symbol'],
            'quote_currency' => $config['currency'],
            'sector' => $config['sector'],
            'country' => $config['country'],
            'exchange' => $config['currency'] === 'USD' ? 'NDQ' : 'EAM',
        ]);

        $buyLocal = round($config['qty'] * $config['buy'], 2);
        $buyEur = round($buyLocal * $fxRate, 2);
        $buyTotal = round($buyEur + $fee, 2);

        Transaction::create([
            'account_id' => $account->id, 'instrument_id' => $instrument->id,
            'executed_at' => $today->copy()->subWeeks($config['buyWeeks'])->addHours(10),
            'type' => 'buy', 'quantity' => $config['qty'], 'price' => $config['buy'],
            'price_currency' => $config['currency'], 'fee' => $fee, 'trade_currency' => $config['currency'],
            'fx_rate_to_eur' => $config['currency'] === 'USD' ? $fxRate : null,
            'local_value' => $buyLocal, 'value_eur' => $buyEur, 'total_eur' => $buyTotal,
            'source' => 'import', 'external_id' => 'demo-'.$externalId++,
        ]);

        $sellLocal = round($config['qty'] * $config['sell'], 2);
        $sellEur = round($sellLocal * $fxRate, 2);
        $sellTotal = round($sellEur - $fee, 2); // proceeds net of fee

        Transaction::create([
            'account_id' => $account->id, 'instrument_id' => $instrument->id,
            'executed_at' => $today->copy()->subWeeks($config['sellWeeks'])->addHours(10),
            'type' => 'sell', 'quantity' => $config['qty'], 'price' => $config['sell'],
            'price_currency' => $config['currency'], 'fee' => $fee, 'trade_currency' => $config['currency'],
            'fx_rate_to_eur' => $config['currency'] === 'USD' ? $fxRate : null,
            'local_value' => $sellLocal, 'value_eur' => $sellEur, 'total_eur' => $sellTotal,
            'source' => 'import', 'external_id' => 'demo-'.$externalId++,
        ]);

        return $buyTotal;
    }

    private function deposit(Account $account, float $amount, Carbon $when): void
    {
        CashMovement::create([
            'account_id' => $account->id, 'occurred_at' => $when,
            'type' => 'deposit', 'amount' => $amount, 'currency' => 'EUR',
            'description' => 'Storting', 'source' => 'import',
        ]);
    }

    private function fee(Account $account, float $amount, Carbon $when, string $description): void
    {
        CashMovement::create([
            'account_id' => $account->id, 'occurred_at' => $when,
            'type' => 'fee', 'amount' => $amount, 'currency' => 'EUR',
            'description' => $description, 'source' => 'import',
        ]);
    }
}
