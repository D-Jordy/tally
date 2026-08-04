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

        $this->assertModelMissing($transaction);
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
