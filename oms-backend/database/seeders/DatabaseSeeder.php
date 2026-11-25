<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderNote;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $users = collect([
            ['name' => 'Admin User', 'email' => 'admin@oms.test', 'role' => 'admin'],
            ['name' => 'Manager User', 'email' => 'manager@oms.test', 'role' => 'manager'],
            ['name' => 'Staff Alice', 'email' => 'staff1@oms.test', 'role' => 'staff'],
            ['name' => 'Staff David', 'email' => 'staff2@oms.test', 'role' => 'staff'],
            ['name' => 'Staff Sara', 'email' => 'staff3@oms.test', 'role' => 'staff'],
        ])->map(fn ($row) => User::firstOrCreate(
            ['email' => $row['email']],
            ['name' => $row['name'], 'password' => Hash::make('password'), 'role' => $row['role'], 'is_active' => true]
        ));

        $customers = collect();
        for ($i = 1; $i <= 100; $i++) {
            $customers->push(Customer::create([
                'name' => fake()->name(),
                'email' => fake()->unique()->safeEmail(),
                'phone' => fake()->phoneNumber(),
                'address' => fake()->address(),
                'is_active' => fake()->boolean(90),
            ]));
        }

        $products = collect();
        for ($i = 1; $i <= 50; $i++) {
            $products->push(Product::create([
                'name' => fake()->unique()->words(3, true),
                'sku' => 'SKU-'.str_pad((string) $i, 5, '0', STR_PAD_LEFT),
                'price_cents' => fake()->numberBetween(500, 50000),
                'stock_quantity' => fake()->numberBetween(10, 500),
                'is_active' => fake()->boolean(95),
            ]));
        }

        $statuses = ['draft', 'pending', 'confirmed', 'processing', 'ready', 'completed', 'cancelled'];
        $priorities = ['low', 'normal', 'high', 'urgent'];
        for ($i = 1; $i <= 520; $i++) {
            $status = fake()->randomElement($statuses);
            $customer = $customers->random();
            $assignee = $users->where('role', 'staff')->random();
            $order = Order::create([
                'order_number' => 'ORD-'.now()->format('Y').'-'.str_pad((string) $i, 6, '0', STR_PAD_LEFT),
                'customer_id' => $customer->id,
                'assigned_to' => fake()->boolean(80) ? $assignee->id : null,
                'created_by' => $users->random()->id,
                'status' => $status,
                'priority' => fake()->randomElement($priorities),
                'order_date' => fake()->dateTimeBetween('-12 months', 'now')->format('Y-m-d'),
                'subtotal_cents' => 0,
                'discount_cents' => fake()->randomElement([0, 500, 1000, 2000]),
                'tax_cents' => 0,
                'total_cents' => 0,
                'tax_rate' => 10,
            ]);

            $subtotal = 0;
            foreach ($products->random(fake()->numberBetween(1, 4)) as $product) {
                $quantity = fake()->numberBetween(1, 5);
                $lineSubtotal = $product->price_cents * $quantity;
                $tax = intdiv($lineSubtotal, 10);
                $order->items()->create([
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'unit_price_cents' => $product->price_cents,
                    'discount_cents' => 0,
                    'tax_cents' => $tax,
                    'line_total_cents' => $lineSubtotal + $tax,
                ]);
                $subtotal += $lineSubtotal;
            }
            $tax = $order->items()->sum('tax_cents');
            $order->update([
                'subtotal_cents' => $subtotal,
                'tax_cents' => $tax,
                'total_cents' => max(0, $subtotal - $order->discount_cents) + $tax,
            ]);
            OrderStatusHistory::create([
                'order_id' => $order->id,
                'user_id' => $users->random()->id,
                'from_status' => null,
                'to_status' => $status,
                'remarks' => 'Seeded order status',
            ]);
            if (fake()->boolean(30)) {
                OrderNote::create([
                    'order_id' => $order->id,
                    'user_id' => $users->random()->id,
                    'body' => fake()->sentence(),
                ]);
            }
        }
    }
}
