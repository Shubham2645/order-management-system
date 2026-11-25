<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OrderStabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_total_applies_line_and_order_discounts_once(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = Customer::create(['name' => 'Test Customer', 'email' => 'customer@example.test']);
        $product = Product::create([
            'name' => 'Widget',
            'sku' => 'WIDGET-1',
            'price_cents' => 1000,
            'stock_quantity' => 10,
        ]);

        $this->actingAs($admin, 'sanctum');
        $order = app(OrderService::class)->create([
            'customer_id' => $customer->id,
            'order_date' => '2026-11-24',
            'tax_rate' => 10,
            'discount_cents' => 50,
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 2,
                'discount_cents' => 100,
            ]],
        ], $admin->id);

        $this->assertSame(2000, $order->subtotal_cents);
        $this->assertSame(190, $order->tax_cents);
        $this->assertSame(2040, $order->total_cents);
        $this->assertStringStartsWith('ORD-2026-', $order->order_number);
    }

    public function test_confirming_deducts_stock_and_cancelling_restores_it(): void
    {
        [$admin, $customer, $product] = $this->makeOrderData(stock: 10);
        $this->actingAs($admin, 'sanctum');
        $service = app(OrderService::class);
        $order = $service->create([
            'customer_id' => $customer->id,
            'order_date' => '2026-11-24',
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
        ], $admin->id);

        $order = $service->changeStatus($order, 'pending', null, $admin->id);
        $order = $service->changeStatus($order, 'confirmed', null, $admin->id);
        $this->assertSame(7, $product->fresh()->stock_quantity);

        $service->changeStatus($order, 'cancelled', 'Customer cancelled', $admin->id);
        $this->assertSame(10, $product->fresh()->stock_quantity);
        $this->assertDatabaseCount('order_status_histories', 4);
    }

    public function test_insufficient_stock_rolls_back_order_creation(): void
    {
        [$admin, $customer, $product] = $this->makeOrderData(stock: 2);
        $this->actingAs($admin, 'sanctum');

        try {
            app(OrderService::class)->create([
                'customer_id' => $customer->id,
                'order_date' => '2026-11-24',
                'status' => 'confirmed',
                'items' => [['product_id' => $product->id, 'quantity' => 3]],
            ], $admin->id);
            $this->fail('Creating a confirmed order without enough stock should fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items', $exception->errors());
        }

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_status_histories', 0);
        $this->assertSame(2, $product->fresh()->stock_quantity);
    }

    public function test_updating_committed_order_reconciles_stock_and_recalculates_totals(): void
    {
        [$admin, $customer, $product] = $this->makeOrderData(stock: 10);
        $this->actingAs($admin, 'sanctum');
        $service = app(OrderService::class);
        $order = $service->create([
            'customer_id' => $customer->id,
            'order_date' => '2026-11-24',
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
        ], $admin->id);
        $order = $service->changeStatus($order, 'pending', null, $admin->id);
        $order = $service->changeStatus($order, 'confirmed', null, $admin->id);

        $order = $service->update($order, [
            'tax_rate' => 5,
            'items' => [['product_id' => $product->id, 'quantity' => 4]],
        ], $admin->id);

        $this->assertSame(6, $product->fresh()->stock_quantity);
        $this->assertSame(4000, $order->subtotal_cents);
        $this->assertSame(200, $order->tax_cents);
        $this->assertSame(4200, $order->total_cents);
        $this->assertSame(4, $order->items->sole()->quantity);
    }

    public function test_failed_committed_order_update_restores_neither_stock_nor_items(): void
    {
        [$admin, $customer, $product] = $this->makeOrderData(stock: 5);
        $this->actingAs($admin, 'sanctum');
        $service = app(OrderService::class);
        $order = $service->create([
            'customer_id' => $customer->id,
            'order_date' => '2026-11-24',
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
        ], $admin->id);
        $order = $service->changeStatus($order, 'pending', null, $admin->id);
        $order = $service->changeStatus($order, 'confirmed', null, $admin->id);

        try {
            $service->update($order, [
                'items' => [['product_id' => $product->id, 'quantity' => 6]],
            ], $admin->id);
            $this->fail('Updating to a quantity above stock should fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items', $exception->errors());
        }

        $this->assertSame(2, $product->fresh()->stock_quantity);
        $this->assertSame(3, $order->fresh()->items->sole()->quantity);
    }

    public function test_tax_rate_update_preserves_order_item_price_snapshots(): void
    {
        [$admin, $customer, $product] = $this->makeOrderData(stock: 10);
        $this->actingAs($admin, 'sanctum');
        $service = app(OrderService::class);
        $order = $service->create([
            'customer_id' => $customer->id,
            'order_date' => '2026-11-24',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ], $admin->id);

        $product->update(['price_cents' => 2000]);
        $order = $service->update($order, ['tax_rate' => 5], $admin->id);

        $this->assertSame(1000, $order->items->sole()->unit_price_cents);
        $this->assertSame(50, $order->tax_cents);
        $this->assertSame(1050, $order->total_cents);
    }

    public function test_order_api_creates_and_updates_order_items(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = Customer::create(['name' => 'API Customer', 'email' => 'api-customer@example.test']);
        $product = Product::create([
            'name' => 'API Widget',
            'sku' => 'API-WIDGET-1',
            'price_cents' => 500,
            'stock_quantity' => 20,
        ]);

        $this->actingAs($admin, 'sanctum');
        $response = $this->postJson('/api/orders', [
            'customer_id' => $customer->id,
            'priority' => 'normal',
            'order_date' => '2026-11-24',
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ])->assertCreated();

        $orderId = $response->json('data.id');
        $this->putJson("/api/orders/{$orderId}", [
            'items' => [['product_id' => $product->id, 'quantity' => 4]],
        ])->assertOk()
            ->assertJsonPath('data.subtotal_cents', 2000)
            ->assertJsonPath('data.total_cents', 2200);

        $this->assertDatabaseCount('order_items', 1);
        $this->assertDatabaseHas('order_items', ['order_id' => $orderId, 'quantity' => 4]);
    }

    public function test_staff_cannot_create_orders(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/orders', [])
            ->assertForbidden();
    }

    public function test_staff_search_cannot_return_unassigned_orders(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = Customer::create(['name' => 'Search Customer', 'email' => 'search@example.test']);
        $visible = $this->createBareOrder($customer, $admin, $staff, 'ORD-VISIBLE-001');
        $hidden = $this->createBareOrder($customer, $admin, null, 'ORD-PRIVATE-002');

        $response = $this->actingAs($staff, 'sanctum')
            ->getJson('/api/orders?search='.$hidden->order_number);

        $response->assertOk()->assertJsonPath('data.data', []);
        $this->assertNotSame($visible->id, $hidden->id);
    }

    public function test_customer_and_product_search_respect_active_filter(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Customer::create(['name' => 'Hidden Search Customer', 'email' => 'hidden-customer@example.test', 'is_active' => true]);
        Product::create(['name' => 'Hidden Search Product', 'sku' => 'HIDDEN-1', 'price_cents' => 100, 'stock_quantity' => 1, 'is_active' => true]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/customers?search=Hidden&is_active=0')
            ->assertOk()
            ->assertJsonPath('data.data', []);

        $this->getJson('/api/products?search=Hidden&is_active=0')
            ->assertOk()
            ->assertJsonPath('data.data', []);
    }

    public function test_api_returns_json_unauthorized_response_without_token(): void
    {
        $this->getJson('/api/orders')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    private function makeOrderData(int $stock): array
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = Customer::create(['name' => 'Test Customer', 'email' => fake()->unique()->safeEmail()]);
        $product = Product::create([
            'name' => 'Widget',
            'sku' => 'WIDGET-'.fake()->unique()->numerify('####'),
            'price_cents' => 1000,
            'stock_quantity' => $stock,
        ]);

        return [$admin, $customer, $product];
    }

    private function createBareOrder(Customer $customer, User $creator, ?User $assignee, string $number): Order
    {
        return Order::create([
            'order_number' => $number,
            'customer_id' => $customer->id,
            'assigned_to' => $assignee?->id,
            'created_by' => $creator->id,
            'status' => 'pending',
            'priority' => 'normal',
            'order_date' => '2026-11-24',
        ]);
    }
}
