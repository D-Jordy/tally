<?php

namespace Tests\Feature;

use App\Actions\ImportBrokerCsv;
use App\Filament\Resources\Accounts\Pages\CreateAccount;
use App\Filament\Resources\Accounts\Pages\ListAccounts;
use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class AccountResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_shows_only_the_authenticated_users_accounts(): void
    {
        $alice = User::factory()->create();
        $mine = Account::factory()->for($alice)->create(['name' => 'Mine']);
        $theirs = Account::factory()->create(['name' => 'Theirs']);

        Livewire::actingAs($alice)
            ->test(ListAccounts::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs]);
    }

    public function test_creating_an_account_sets_the_user_id(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(CreateAccount::class)
            ->set('data.name', 'Beleggingsrekening')
            ->set('data.broker', 'degiro')
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('accounts', [
            'name' => 'Beleggingsrekening',
            'broker' => 'degiro',
            'user_id' => $user->id,
        ]);
    }

    public function test_import_action_runs_the_transaction_importer(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();

        $header = 'Datum,Tijd,Product,ISIN,Beurs,Plaats,Aantal,Koers,,Lokale waarde,,Waarde EUR,Wisselkoers,AutoFX,Kosten,Totaal,Order,Id';
        $row = '02-01-2026,09:30,ASML Holding,NL0010273215,EAM,XAMS,10,"650,00",EUR,"-6500,00",EUR,"-6500,00",,,"-2,00","-6502,00",,test-uuid-0001';
        Storage::disk('local')->put('imports/transactions/test.csv', $header."\n".$row."\n");

        $result = (new ImportBrokerCsv)->transactions($account, 'imports/transactions/test.csv');

        $this->assertSame(1, $result->inserted);
        $this->assertSame([], $result->errors);
        $this->assertDatabaseHas('transactions', [
            'account_id' => $account->id,
            'external_id' => 'test-uuid-0001',
            'type' => 'buy',
        ]);
    }
}
