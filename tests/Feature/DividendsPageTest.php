<?php

namespace Tests\Feature;

use App\Actions\ComputeIncomingDividends;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DividendsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Disable Vite manifest resolution so Blade can render in tests without a built frontend.
        $this->withoutVite();
    }

    private function mockEmptyCompute(): void
    {
        $empty = [
            'confirmed' => [],
            'events'    => [],
            'monthly'   => array_map(
                fn ($i) => ['month' => now()->addMonths($i)->format('Y-m'), 'expected_eur' => 0.0],
                range(0, 11)
            ),
            'summary' => [
                'next_12m_total_eur'        => 0.0,
                'trailing_12m_received_eur' => 0.0,
                'instrument_count'          => 0,
                'confirmed_count'           => 0,
            ],
        ];

        $this->mock(ComputeIncomingDividends::class)
             ->shouldReceive('forUser')
             ->andReturn($empty);
    }

    public function test_dividends_page_renders_for_authenticated_user(): void
    {
        $this->mockEmptyCompute();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/dividends')
            ->assertOk();
    }

    public function test_dividends_page_returns_correct_inertia_component(): void
    {
        $this->mockEmptyCompute();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/dividends')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dividends/Index')
                ->has('confirmed')
                ->has('events')
                ->has('monthly')
                ->has('summary')
            );
    }

    public function test_dividends_page_empty_state_for_user_with_no_data(): void
    {
        $this->mockEmptyCompute();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/dividends')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('events', [])
                ->where('summary.next_12m_total_eur', 0)
                ->where('summary.instrument_count', 0)
            );
    }

    public function test_dividends_page_redirects_guests(): void
    {
        $this->get('/dividends')->assertRedirect('/login');
    }
}
