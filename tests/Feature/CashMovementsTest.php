<?php

namespace Tests\Feature;

use App\Actions\ComputeCashLedger;
use App\Actions\ComputePortfolio;
use App\Filament\Resources\Accounts\Pages\EditAccount;
use App\Filament\Resources\Accounts\RelationManagers\CashMovementsRelationManager;
use App\Models\Account;
use App\Models\CashMovement;
use App\Models\FxRate;
use App\Models\Instrument;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CashMovementsTest extends TestCase
{
    use RefreshDatabase;

    private function movement(Account $account, string $type, float $amount, string $currency = 'EUR', string $date = '2024-03-01'): void
    {
        CashMovement::create([
            'account_id' => $account->id,
            'occurred_at' => $date,
            'type' => $type,
            'amount' => $amount,
            'currency' => $currency,
            'description' => ucfirst($type),
            'source' => 'import',
        ]);
    }

    public function test_totals_match_the_cash_figures_the_portfolio_computes(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();

        // ComputePortfolio returns a zeroed summary for an account without trades,
        // so the comparison needs one transaction on file to be meaningful.
        Transaction::factory()->for($account)->for(Instrument::factory())->create(['type' => 'buy']);

        $this->movement($account, 'deposit', 1000, date: '2024-01-01');
        $this->movement($account, 'deposit', 500, date: '2024-02-01');
        $this->movement($account, 'fee', -10, date: '2024-02-02');
        $this->movement($account, 'promo', 2.50, date: '2024-02-03');

        $ledger = app(ComputeCashLedger::class)->forAccount($account);
        $summary = app(ComputePortfolio::class)->forUser($user)['summary'];

        $this->assertSame(1500.0, $ledger['totals']['deposit']);
        $this->assertSame((float) $summary['deposited_eur'], $ledger['totals']['deposit']);

        // ComputePortfolio nets promo against fees; the ledger keeps them apart,
        // so the fee KPI is the sum of the two ledger buckets.
        $this->assertSame((float) $summary['total_fees_eur'], $ledger['totals']['fee'] + $ledger['totals']['promo']);
    }

    public function test_running_balance_is_chronological_and_rows_come_back_newest_first(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();

        $this->movement($account, 'deposit', 1000, date: '2024-01-01');
        $this->movement($account, 'fee', -10, date: '2024-02-01');
        $this->movement($account, 'dividend', 40, date: '2024-03-01');

        $ledger = app(ComputeCashLedger::class)->forAccount($account);

        $this->assertSame(['2024-03-01', '2024-02-01', '2024-01-01'], array_column($ledger['rows'], 'occurred_at'));
        $this->assertSame([1030.0, 990.0, 1000.0], array_column($ledger['rows'], 'balance_eur'));
        $this->assertSame(1030.0, $ledger['total_eur']);
    }

    public function test_foreign_currency_rows_convert_with_the_latest_rate(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();

        $this->movement($account, 'dividend', 100, 'USD');
        FxRate::create(['date' => '2024-01-01', 'currency' => 'USD', 'rate_to_eur' => 0.80]);
        FxRate::create(['date' => '2024-06-01', 'currency' => 'USD', 'rate_to_eur' => 0.90]);

        $ledger = app(ComputeCashLedger::class)->forAccount($account);

        $this->assertSame(90.0, $ledger['rows'][0]['amount_eur']);
        $this->assertSame(90.0, $ledger['totals']['dividend']);
    }

    public function test_foreign_currency_row_without_a_rate_does_not_move_the_balance(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();

        $this->movement($account, 'deposit', 1000);
        $this->movement($account, 'dividend', 100, 'USD', date: '2024-04-01');

        $ledger = app(ComputeCashLedger::class)->forAccount($account);

        $this->assertNull($ledger['rows'][0]['amount_eur']);
        $this->assertSame(1000.0, $ledger['total_eur']);
        $this->assertArrayNotHasKey('dividend', $ledger['totals']);
    }

    public function test_account_without_cash_movements_returns_an_empty_ledger(): void
    {
        $account = Account::factory()->for(User::factory())->create();

        $ledger = app(ComputeCashLedger::class)->forAccount($account);

        $this->assertSame([], $ledger['rows']);
        $this->assertSame(0.0, $ledger['total_eur']);
    }

    public function test_the_relation_manager_lists_the_movements_with_their_eur_totals(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create(['name' => 'DEGIRO']);
        $this->movement($account, 'deposit', 1000);
        $this->movement($account, 'dividend', 100, 'USD', date: '2024-04-01');
        FxRate::create(['date' => '2024-04-01', 'currency' => 'USD', 'rate_to_eur' => 0.90]);

        Livewire::actingAs($user)
            ->test(CashMovementsRelationManager::class, [
                'ownerRecord' => $account,
                'pageClass' => EditAccount::class,
            ])
            ->assertSuccessful()
            ->assertCanSeeTableRecords($account->cashMovements)
            // Own currency by symbol, euro notation whatever the UI language is.
            ->assertSee('$ 100,00')
            ->assertDontSee('USD')
            // Header totals: the deposit bucket and the converted dividend.
            ->assertSee('1.000,00')
            ->assertSee('90,00')
            ->assertDontSee(__('accounts.cash.empty'));

        // Livewire::test skips the panel's real render path — hit the page too.
        $this->actingAs($user)
            ->get(EditAccount::getUrl(['record' => $account]))
            ->assertSuccessful()
            ->assertSee(__('accounts.cash.heading'));
    }

    public function test_the_relation_manager_shows_an_empty_state_without_cash_movements(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();

        Livewire::actingAs($user)
            ->test(CashMovementsRelationManager::class, [
                'ownerRecord' => $account,
                'pageClass' => EditAccount::class,
            ])
            ->assertSuccessful()
            ->assertSee(__('accounts.cash.empty'));
    }
}
