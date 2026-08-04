<?php

namespace Tests\Feature;

use App\Actions\ComputePortfolio;
use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Resources\Accounts\Pages\EditAccount;
use App\Filament\Resources\Accounts\RelationManagers\TransactionsRelationManager;
use App\Models\Account;
use App\Models\Instrument;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Import\ImportResult;
use App\Services\Import\TransactionImporter;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class AccountTransactionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_relation_manager_shows_only_this_accounts_transactions(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $other = Account::factory()->for($user)->create();

        $mine = Transaction::factory()->for($account)->create();
        $elsewhere = Transaction::factory()->for($other)->create();

        $this->relationManager($account, $user)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$elsewhere]);
    }

    public function test_a_manually_created_transaction_gets_a_dedupe_hash_and_is_flagged_manual(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $instrument = Instrument::factory()->create();

        $this->create($account, $user, $this->formData($instrument))
            ->assertHasNoActionErrors();

        $transaction = Transaction::query()->sole();

        $this->assertSame($account->id, $transaction->account_id);
        $this->assertSame('manual', $transaction->source);
        $this->assertSame(
            Transaction::makeDedupeHash($transaction->executed_at, $instrument->id, 'buy', 10, 650, 2),
            $transaction->dedupe_hash,
        );
    }

    public function test_entering_a_trade_that_already_exists_is_a_field_error_not_a_crash(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $instrument = Instrument::factory()->create();

        Transaction::create([
            ...$this->formData($instrument),
            'account_id' => $account->id,
            'source' => 'manual',
        ]);

        // Asserting the message, not just the field: "required" would otherwise pass
        // this test without the duplicate guard ever running.
        $this->create($account, $user, $this->formData($instrument))
            ->assertHasActionErrors(['executed_at' => __('transactions.validation.duplicate')]);

        $this->assertSame(1, Transaction::query()->count());
    }

    /**
     * The headline behaviour: a trade entered by hand before the export existed is
     * recognised and taken over, instead of landing next to its own CSV row.
     */
    public function test_an_import_adopts_a_hand_entered_row_for_the_same_trade(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $instrument = Instrument::factory()->create(['isin' => 'NL0010273215', 'name' => 'ASML']);

        // Rounded and an hour off — the shape of a real hand entry, and nothing the
        // exact-hash match would ever catch.
        $this->create($account, $user, [
            ...$this->formData($instrument),
            'executed_at' => '2026-01-02 10:30:00',
            'fee' => '0',
            'total_eur' => '-6500',
        ])->assertHasNoActionErrors();

        $manualId = Transaction::query()->sole()->id;

        $result = $this->importCsv($account);

        $this->assertSame(1, Transaction::query()->count());

        $adopted = Transaction::query()->sole();
        $this->assertSame($manualId, $adopted->id);
        $this->assertSame('import', $adopted->source);
        $this->assertSame('-2.00000000', $adopted->fee);
        $this->assertSame('09:30', $adopted->executed_at->format('H:i'));
        $this->assertSame(0, $result->inserted);
    }

    public function test_a_hand_entered_row_for_a_different_trade_is_left_alone(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $instrument = Instrument::factory()->create(['isin' => 'NL0010273215', 'name' => 'ASML']);

        // Same day and instrument, different quantity — a genuinely separate trade.
        $this->create($account, $user, [...$this->formData($instrument), 'quantity' => '3'])
            ->assertHasNoActionErrors();

        $this->importCsv($account);

        $this->assertSame(2, Transaction::query()->count());
    }

    public function test_a_hand_edited_row_survives_a_reimport_of_the_same_csv(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();

        $this->importCsv($account);
        $transaction = Transaction::query()->sole();
        $originalHash = $transaction->dedupe_hash;

        $this->edit($account, $user, $transaction, ['quantity' => '12'])
            ->assertHasNoActionErrors();

        $transaction->refresh();
        $this->assertSame('manual', $transaction->source);
        $this->assertSame('12.00000000', $transaction->quantity);
        // Frozen on purpose: the re-import below has to match this row on it.
        $this->assertSame($originalHash, $transaction->dedupe_hash);

        $result = $this->importCsv($account);

        $this->assertSame(0, $result->inserted);
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

    public function test_a_deleted_row_stays_deleted_across_a_reimport_and_can_be_restored(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();

        $this->importCsv($account);
        $transaction = Transaction::query()->sole();

        $this->relationManager($account, $user)
            ->callAction(TestAction::make('delete')->table($transaction));

        $result = $this->importCsv($account);

        $this->assertSame(0, $result->inserted);
        $this->assertSame(0, Transaction::query()->count());
        $this->assertSame(1, Transaction::withTrashed()->count());

        $this->relationManager($account, $user)
            ->filterTable('trashed', 'only')
            ->callAction(TestAction::make('restore')->table($transaction));

        $this->assertSame(1, Transaction::query()->count());
    }

    public function test_the_portfolio_reflects_a_manual_edit(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();

        $this->importCsv($account);

        $this->edit($account, $user, Transaction::query()->sole(), ['quantity' => '25'])
            ->assertHasNoActionErrors();

        $positions = app(ComputePortfolio::class)->forUser($user->fresh())['positions'];

        $this->assertSame(25.0, $positions[0]['quantity']);
    }

    public function test_every_filter_narrows_the_list(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $apple = Instrument::factory()->create(['name' => 'Apple']);
        $shell = Instrument::factory()->create(['name' => 'Shell']);

        $old = Transaction::factory()->for($account)->for($apple)
            ->create(['type' => 'buy', 'executed_at' => '2024-01-05 10:00']);
        $recent = Transaction::factory()->for($account)->for($shell)
            ->create(['type' => 'sell', 'executed_at' => '2026-01-05 10:00']);

        $filters = [
            ['instrument_id', $shell->id, $recent, $old],
            ['type', 'sell', $recent, $old],
            ['executed_at', ['from' => '2025-01-01'], $recent, $old],
            ['executed_at', ['until' => '2025-01-01'], $old, $recent],
        ];

        foreach ($filters as [$name, $value, $visible, $hidden]) {
            $this->relationManager($account, $user)
                ->filterTable($name, $value)
                ->assertCanSeeTableRecords([$visible])
                ->assertCanNotSeeTableRecords([$hidden]);
        }
    }

    public function test_the_account_page_boots_with_the_transactions_relation_manager(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        Transaction::factory()->for($account)->create();

        // The rows themselves arrive in the relation manager's own Livewire request,
        // covered above — this only pins that the page still boots with it attached.
        $this->actingAs($user)
            ->get(AccountResource::getUrl('edit', ['record' => $account]))
            ->assertOk();
    }

    private function relationManager(Account $account, User $user): Testable
    {
        return Livewire::actingAs($user)->test(TransactionsRelationManager::class, [
            'ownerRecord' => $account,
            'pageClass' => EditAccount::class,
        ]);
    }

    /**
     * Mount, then set each field: passing data straight to callAction() is a silent
     * no-op here, the same way fillForm() is.
     *
     * @param  array<string, mixed>  $data
     */
    private function create(Account $account, User $user, array $data): Testable
    {
        return $this->runAction(
            $this->relationManager($account, $user),
            TestAction::make('create')->table(),
            $data,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function edit(Account $account, User $user, Transaction $record, array $data): Testable
    {
        return $this->runAction(
            $this->relationManager($account, $user),
            TestAction::make('edit')->table($record),
            $data,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function runAction(Testable $test, TestAction $action, array $data): Testable
    {
        $test->mountAction($action);

        foreach ($data as $field => $value) {
            $test->set("mountedActions.0.data.{$field}", $value);
        }

        return $test->callMountedAction();
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(Instrument $instrument): array
    {
        return [
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
    }

    private function importCsv(Account $account): ImportResult
    {
        $header = 'Datum,Tijd,Product,ISIN,Beurs,Plaats,Aantal,Koers,,Lokale waarde,,Waarde EUR,Wisselkoers,AutoFX,Kosten,Totaal,Order,Id';
        $row = '02-01-2026,09:30,ASML,NL0010273215,EAM,XAMS,10,"650,00",EUR,"-6500,00",EUR,"-6500,00",,,"-2,00","-6502,00",,uuid-1';

        $path = tempnam(sys_get_temp_dir(), 'degiro').'.csv';
        file_put_contents($path, $header."\n".$row."\n");

        return (new TransactionImporter)->import($account, $path);
    }
}
