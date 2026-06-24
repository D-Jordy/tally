<?php

namespace Tests\Feature;

use App\Actions\ComputeProjections;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectionsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function mockCompute(int $horizon = 5): void
    {
        $payload = [
            'horizon_years'           => $horizon,
            'growth_rate'             => 0.08,
            'prior_rate'              => 0.09,
            'analyst_rate'            => 0.07,
            'annual_contribution_eur' => 0.0,
            'starting_value_eur'      => 10000.0,
            'value_series'            => array_map(
                fn ($y) => ['year' => $y, 'projected_value_eur' => 10000 * ((1.08) ** $y)],
                range(0, $horizon)
            ),
            'dividend_series' => array_map(
                fn ($y) => ['year' => $y, 'projected_dividends_eur' => 500 * ((1.08) ** $y)],
                range(0, $horizon)
            ),
        ];

        $this->mock(ComputeProjections::class)
             ->shouldReceive('forUser')
             ->andReturn($payload);
    }

    public function test_projections_page_renders_for_authenticated_user(): void
    {
        $this->mockCompute();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/projections')
            ->assertOk();
    }

    public function test_projections_page_returns_correct_inertia_component(): void
    {
        $this->mockCompute();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/projections')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Projections/Index')
                ->has('horizon_years')
                ->has('growth_rate')
                ->has('prior_rate')
                ->has('analyst_rate')
                ->has('annual_contribution_eur')
                ->has('starting_value_eur')
                ->has('value_series')
                ->has('dividend_series')
            );
    }

    public function test_invalid_horizon_defaults_to_five(): void
    {
        $this->mockCompute(5);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/projections?horizon=99')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('horizon_years', 5)
            );
    }

    public function test_projections_page_redirects_guests(): void
    {
        $this->get('/projections')->assertRedirect('/login');
    }
}
