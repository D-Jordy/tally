<?php

namespace Tests\Feature;

use App\Actions\ComputePortfolio;
use App\Filament\Resources\Transactions\Pages\CreateTransaction;
use App\Filament\Resources\Transactions\Pages\EditTransaction;
use App\Filament\Resources\Transactions\Pages\ListTransactions;
use App\Filament\Resources\Transactions\TransactionResource;
use App\Models\Account;
use App\Models\Instrument;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Import\ImportResult;
use App\Services\Import\TransactionImporter;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class TransactionResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_shows_only_transactions_of_the_authenticated_users_accounts(): void
    {
        $user = User::factory()->create();
        $mine = Transaction::factory()->for(Account::factory()->for($user))->create();
        $theirs = Transaction::factory()->for(Account::factory())->create();

        Livewire::actingAs($user)
            ->test(ListTransactions::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs]);
    }

    public function test_a_manually_created_transaction_gets_a_dedupe_hash_and_is_flagged_manual(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $instrument = Instrument::factory()->create();

        Livewire::actingAs($user)
            ->test(CreateTransaction::class)
            ->set('data.account_id', $account->id)
            ->set('data.instrument_id', $instrument->id)
            ->set('data.executed_at', '2026-01-02 09:30:00')
            ->set('data.type', 'buy')
            ->set('data.quantity', '10')
            ->set('data.price', '650')
            ->set('data.price_currency', 'EUR')
            ->set('data.trade_currency', 'EUR')
            ->set('data.fee', '2')
            ->set('data.local_value', '-6500')
            ->set('data.value_eur', '-6500')
            ->set('data.total_eur', '-6502')
            ->call('create')
            ->assertHasNoFormErrors();

        $transaction = Transaction::query()->sole();

        $this->assertSame('manual', $transaction->source);
        $this->assertSame(
            Transaction::makeDedupeHash(
                $transaction->executed_at, $instrument->id, 'buy', 10, 650, 2
            ),
            $transaction->dedupe_hash,
        );
    }

    public function test_a_hand_edited_row_survives_a_reimport_of_the_same_csv(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();

        $this->importCsv($account);

        $transaction = Transaction::query()->sole();
        $originalHash = $transaction->dedupe_hash;

        Livewire::actingAs($user)
            ->test(EditTransaction::class, ['record' => $transaction->getRouteKey()])
            ->set('data.quantity', '12')
            ->call('save')
            ->assertHasNoFormErrors();

        $transaction->refresh();
        $this->assertSame('manual', $transaction->source);
        $this->assertSame('12.00000000', $transaction->quantity);
        // Frozen on purpose: the re-import below has to match this row on it.
        $this->assertSame($originalHash, $transaction->dedupe_hash);

        $result = $this->importCsv($account);

        $this->assertSame(0, $result->inserted);
        $this->assertSame(1, $result->skipped);
        $this->assertSame(1, Transaction::query()->count());
        $this->assertSame('12.00000000', $transaction->fresh()->quantity);
    }

    public function test_an_imported_row_is_still_overwritten_by_a_reimport(): void
    {
        $account = Account::factory()->create();

        $this->importCsv($account);
        Transaction::query()->sole()->update(['quantity' => '99']);

        $this->importCsv($account);

        $this->assertSame(1, Transaction::query()->count());
        $this->assertSame('10.00000000', Transaction::query()->sole()->quantity);
    }

    public function test_the_portfolio_reflects_a_manual_edit(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();

        $this->importCsv($account);
        $transaction = Transaction::query()->sole();

        Livewire::actingAs($user)
            ->test(EditTransaction::class, ['record' => $transaction->getRouteKey()])
            ->set('data.quantity', '25')
            ->call('save')
            ->assertHasNoFormErrors();

        $positions = app(ComputePortfolio::class)->forUser($user->fresh())['positions'];

        $this->assertSame(25.0, $positions[0]['quantity']);
    }

    public function test_every_filter_narrows_the_list(): void
    {
        $user = User::factory()->create();
        $accountA = Account::factory()->for($user)->create(['name' => 'A']);
        $accountB = Account::factory()->for($user)->create(['name' => 'B']);
        $apple = Instrument::factory()->create(['name' => 'Apple']);
        $shell = Instrument::factory()->create(['name' => 'Shell']);

        $old = Transaction::factory()->for($accountA)->for($apple)
            ->create(['type' => 'buy', 'executed_at' => '2024-01-05 10:00']);
        $recent = Transaction::factory()->for($accountB)->for($shell)
            ->create(['type' => 'sell', 'executed_at' => '2026-01-05 10:00']);

        $filters = [
            ['account', $accountA->id, $old, $recent],
            ['instrument_id', $shell->id, $recent, $old],
            ['type', 'sell', $recent, $old],
            ['executed_at', ['from' => '2025-01-01'], $recent, $old],
            ['executed_at', ['until' => '2025-01-01'], $old, $recent],
        ];

        foreach ($filters as [$name, $value, $visible, $hidden]) {
            Livewire::actingAs($user)
                ->test(ListTransactions::class)
                ->filterTable($name, $value)
                ->assertCanSeeTableRecords([$visible])
                ->assertCanNotSeeTableRecords([$hidden]);
        }
    }

    public function test_entering_a_trade_that_already_exists_is_a_field_error_not_a_crash(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $instrument = Instrument::factory()->create();

        $fields = [
            'account_id' => $account->id,
            'instrument_id' => $instrument->id,
            'executed_at' => '2026-01-02 09:30:00',
            'type' => 'buy',
            'quantity' => '10',
            'price' => '650',
            'price_currency' => 'EUR',
            'trade_currency' => 'EUR',
            'fee' => '2',
            'total_eur' => '-6502',
        ];

        Transaction::create([...$fields, 'source' => 'manual']);

        $test = Livewire::actingAs($user)->test(CreateTransaction::class);

        foreach ($fields as $key => $value) {
            $test->set("data.{$key}", $value);
        }

        $test->call('create')->assertHasFormErrors(['executed_at']);

        $this->assertSame(1, Transaction::query()->count());
    }

    public function test_a_deleted_row_stays_deleted_across_a_reimport_and_can_be_restored(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();

        $this->importCsv($account);
        $transaction = Transaction::query()->sole();

        Livewire::actingAs($user)
            ->test(ListTransactions::class)
            ->callAction(TestAction::make('delete')->table($transaction));

        $result = $this->importCsv($account);

        $this->assertSame(0, $result->inserted);
        $this->assertSame(1, $result->skipped);
        $this->assertSame(0, Transaction::query()->count());
        $this->assertSame(1, Transaction::withTrashed()->count());

        Livewire::actingAs($user)
            ->test(ListTransactions::class)
            ->filterTable('trashed', 'only')
            ->callAction(TestAction::make('restore')->table($transaction));

        $this->assertSame(1, Transaction::query()->count());
    }

    public function test_the_instrument_options_are_limited_to_your_own_but_a_new_one_can_be_added(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();

        $mine = Instrument::factory()->create(['name' => 'Mine']);
        Transaction::factory()->for($account)->for($mine)->create();

        $theirs = Instrument::factory()->create(['name' => 'Theirs']);
        Transaction::factory()->for(Account::factory())->for($theirs)->create();

        $this->actingAs($user);

        // Same query the select searches over — the Account global scope only
        // applies once someone is authenticated.
        $found = Instrument::query()
            ->whereHas('transactions.account')
            ->pluck('id');

        $this->assertTrue($found->contains($mine->id));
        $this->assertFalse($found->contains($theirs->id));

        // A trade that never came from a DEGIRO export still needs a way in: an
        // instrument created inline must survive validation on save.
        $fresh = Instrument::create(['name' => 'Brand New', 'isin' => 'NL0000000001']);

        Livewire::actingAs($user)
            ->test(CreateTransaction::class)
            ->set('data.account_id', $account->id)
            ->set('data.instrument_id', $fresh->id)
            ->set('data.executed_at', '2026-02-02 09:30:00')
            ->set('data.type', 'buy')
            ->set('data.quantity', '5')
            ->set('data.price', '100')
            ->set('data.price_currency', 'EUR')
            ->set('data.trade_currency', 'EUR')
            ->set('data.fee', '1')
            ->set('data.total_eur', '-501')
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('transactions', ['instrument_id' => $fresh->id, 'source' => 'manual']);
    }

    public function test_the_real_routes_render(): void
    {
        $user = User::factory()->create();
        $transaction = Transaction::factory()->for(Account::factory()->for($user))->create();

        $this->actingAs($user)->get(TransactionResource::getUrl('index'))->assertOk();
        $this->actingAs($user)->get(TransactionResource::getUrl('create'))->assertOk();
        $this->actingAs($user)->get(TransactionResource::getUrl('edit', ['record' => $transaction]))->assertOk();
    }

    public function test_a_transaction_can_be_deleted_from_the_list(): void
    {
        $user = User::factory()->create();
        $transaction = Transaction::factory()->for(Account::factory()->for($user))->create();

        Livewire::actingAs($user)
            ->test(ListTransactions::class)
            ->callAction(TestAction::make('delete')->table($transaction));

        $this->assertSoftDeleted($transaction);
    }

    private function importCsv(Account $account): ImportResult
    {
        $header = 'Datum,Tijd,Product,ISIN,Beurs,Plaats,Aantal,Koers,,Lokale waarde,,Waarde EUR,Wisselkoers,AutoFX,Kosten,Totaal,Order,Id';
        $row = '02-01-2026,09:30,ASML Holding,NL0010273215,EAM,XAMS,10,"650,00",EUR,"-6500,00",EUR,"-6500,00",,,"-2,00","-6502,00",,uuid-0001';

        Storage::disk('local')->put('imports/transactions/reimport.csv', $header."\n".$row."\n");

        return (new TransactionImporter)->import(
            $account,
            Storage::disk('local')->path('imports/transactions/reimport.csv')
        );
    }
}
