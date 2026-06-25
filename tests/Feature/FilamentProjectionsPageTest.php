<?php

namespace Tests\Feature;

use App\Filament\Pages\Projections;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FilamentProjectionsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_renders_with_default_horizon(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(Projections::class)
            ->assertSuccessful()
            ->assertSee(__('projections.kpi.expected', ['years' => 5]));
    }

    public function test_horizon_toggle_updates_the_kpi_label(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(Projections::class)
            ->set('horizon', 10)
            ->assertSee(__('projections.kpi.expected', ['years' => 10]));
    }

    public function test_annual_contribution_persists_to_user_settings(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(Projections::class)
            ->set('annualContribution', 6000)
            ->assertSuccessful();

        $this->assertSame(6000.0, (float) $user->fresh()->settings['annual_contribution_eur']);
    }
}
