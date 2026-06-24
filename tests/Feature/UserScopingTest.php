<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserScopingTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_an_account_while_authenticated_sets_the_user_id(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);
        $account = Account::create(['broker' => 'degiro', 'name' => 'Main']);

        $this->assertSame($user->id, $account->user_id);
    }

    public function test_global_scope_isolates_accounts_per_user(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        Account::factory()->for($alice)->create(['name' => 'Alice account']);
        Account::factory()->for($bob)->create(['name' => 'Bob account']);

        $this->actingAs($alice);

        $this->assertCount(1, Account::all());
        $this->assertSame('Alice account', Account::first()->name);
    }

    public function test_scope_is_bypassed_without_an_authenticated_user(): void
    {
        Account::factory()->count(3)->create();

        // No auth context (jobs, importers, Artisan): every record is visible.
        $this->assertCount(3, Account::all());
    }

    public function test_every_user_can_access_the_panel(): void
    {
        $user = User::factory()->create();

        $this->assertTrue($user->canAccessPanel(Filament::getPanel('app')));
    }

    public function test_authenticated_user_can_load_the_panel_dashboard(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/')->assertSuccessful();
    }
}
