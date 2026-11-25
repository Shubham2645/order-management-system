<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerProductManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_update_and_remove_unreferenced_catalog_records(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin, 'sanctum');

        $customer = $this->postJson('/api/customers', [
            'name' => 'Morgan Customer',
            'email' => 'morgan@example.test',
            'phone' => '555-0100',
        ])->assertCreated()->json('data');
        $this->putJson("/api/customers/{$customer['id']}", ['name' => 'Morgan Updated'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Morgan Updated');

        $product = $this->postJson('/api/products', [
            'name' => 'Sample Product',
            'sku' => 'SAMPLE-001',
            'price_cents' => 1299,
            'stock_quantity' => 15,
        ])->assertCreated()->json('data');
        $this->putJson("/api/products/{$product['id']}", ['price_cents' => 1499])
            ->assertOk()
            ->assertJsonPath('data.price_cents', 1499);

        $this->deleteJson("/api/customers/{$customer['id']}")->assertOk();
        $this->deleteJson("/api/products/{$product['id']}")->assertOk();
        $this->assertDatabaseMissing('customers', ['id' => $customer['id']]);
        $this->assertDatabaseMissing('products', ['id' => $product['id']]);
    }

    public function test_removing_records_used_by_orders_deactivates_instead_of_deleting(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = Customer::create(['name' => 'Linked Customer', 'email' => 'linked@example.test']);
        $product = Product::create([
            'name' => 'Linked Product',
            'sku' => 'LINKED-001',
            'price_cents' => 500,
            'stock_quantity' => 4,
        ]);
        $order = Order::create([
            'order_number' => 'ORD-LINKED-001',
            'customer_id' => $customer->id,
            'created_by' => $admin->id,
            'status' => 'pending',
            'priority' => 'normal',
            'order_date' => '2026-11-24',
        ]);
        $order->items()->create([
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price_cents' => 500,
            'discount_cents' => 0,
            'tax_cents' => 50,
            'line_total_cents' => 550,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/customers/{$customer->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Customer deactivated');
        $this->deleteJson("/api/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Product deactivated');

        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'is_active' => false]);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'is_active' => false]);
    }

    public function test_non_admin_users_cannot_mutate_customers_or_products(): void
    {
        $manager = User::factory()->create(['role' => 'manager']);

        $this->actingAs($manager, 'sanctum')
            ->postJson('/api/customers', ['name' => 'No Access', 'email' => 'denied@example.test'])
            ->assertForbidden();
        $this->postJson('/api/products', [
            'name' => 'No Access',
            'sku' => 'DENIED-001',
            'price_cents' => 100,
            'stock_quantity' => 1,
        ])->assertForbidden();
    }
}
