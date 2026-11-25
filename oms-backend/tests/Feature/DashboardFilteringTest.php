<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardFilteringTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_applies_date_range_and_monthly_revenue_grouping(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = Customer::create(['name' => 'Dashboard Customer', 'email' => 'dashboard@example.test']);
        $this->createOrder($customer, $admin, 'ORD-DASH-001', 'completed', '2026-03-01', 1000);
        $this->createOrder($customer, $admin, 'ORD-DASH-002', 'completed', '2026-03-12', 2000);
        $this->createOrder($customer, $admin, 'ORD-DASH-003', 'pending', '2026-03-18', 3000);
        $this->createOrder($customer, $admin, 'ORD-DASH-004', 'completed', '2026-04-01', 9000);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/dashboard?from_date=2026-03-01&to_date=2026-03-31&group_by=month')
            ->assertOk()
            ->assertJsonPath('data.total_orders', 3)
            ->assertJsonPath('data.completed_orders', 2)
            ->assertJsonPath('data.pending_orders', 1)
            ->assertJsonPath('data.total_revenue_cents', 3000)
            ->assertJsonPath('data.average_order_value_cents', 1500)
            ->assertJsonPath('data.group_by', 'month');

        $this->assertSame('2026-03', $response->json('data.revenue_trend.0.period'));
        $this->assertSame(3000, (int) $response->json('data.revenue_trend.0.revenue_cents'));
    }

    public function test_dashboard_defaults_the_start_date_relative_to_a_custom_end_date(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $toDate = now()->subDays(60);
        $expectedFromDate = $toDate->copy()->subDays(29)->toDateString();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/dashboard?to_date='.$toDate->toDateString())
            ->assertOk()
            ->assertJsonPath('data.from_date', $expectedFromDate)
            ->assertJsonPath('data.to_date', $toDate->toDateString());
    }

    private function createOrder(Customer $customer, User $creator, string $number, string $status, string $date, int $total): void
    {
        Order::create([
            'order_number' => $number,
            'customer_id' => $customer->id,
            'created_by' => $creator->id,
            'status' => $status,
            'priority' => 'normal',
            'order_date' => $date,
            'total_cents' => $total,
        ]);
    }
}
