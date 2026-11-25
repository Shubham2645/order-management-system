<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderFilteringTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_filters_support_unassigned_priority_and_sorting(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff']);
        $customer = Customer::create(['name' => 'Filter Customer', 'email' => 'filter@example.test']);

        $unassigned = $this->createOrder($customer, $admin, null, 'ORD-FILTER-UNASSIGNED', 'urgent', 500, '2026-11-20');
        $this->createOrder($customer, $admin, $staff, 'ORD-FILTER-ASSIGNED', 'urgent', 1000, '2026-11-21');
        $this->createOrder($customer, $admin, null, 'ORD-FILTER-LOW', 'low', 200, '2026-11-22');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/orders?assigned_to=unassigned&priority=urgent&sort=total_cents&direction=asc')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.id', $unassigned->id);
    }

    public function test_staff_can_view_only_orders_assigned_to_them(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = Customer::create(['name' => 'Access Customer', 'email' => 'access@example.test']);
        $assigned = $this->createOrder($customer, $admin, $staff, 'ORD-ACCESS-ASSIGNED', 'normal', 500, '2026-11-20');
        $unassigned = $this->createOrder($customer, $admin, null, 'ORD-ACCESS-UNASSIGNED', 'normal', 500, '2026-11-21');

        $this->actingAs($staff, 'sanctum')
            ->getJson("/api/orders/{$assigned->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $assigned->id);

        $this->getJson("/api/orders/{$unassigned->id}")->assertForbidden();
    }

    private function createOrder(Customer $customer, User $creator, ?User $assignee, string $number, string $priority, int $total, string $date): Order
    {
        return Order::create([
            'order_number' => $number,
            'customer_id' => $customer->id,
            'assigned_to' => $assignee?->id,
            'created_by' => $creator->id,
            'status' => 'pending',
            'priority' => $priority,
            'order_date' => $date,
            'total_cents' => $total,
        ]);
    }
}
