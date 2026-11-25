<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderService
{
    public const TRANSITIONS = [
        'draft' => ['pending', 'cancelled'],
        'pending' => ['confirmed', 'cancelled'],
        'confirmed' => ['processing', 'cancelled'],
        'processing' => ['ready', 'cancelled'],
        'ready' => ['completed', 'cancelled'],
        'completed' => [],
        'cancelled' => [],
    ];

    public function create(array $data, int $userId): Order
    {
        return DB::transaction(function () use ($data, $userId) {
            $order = new Order;
            $order->fill([
                'order_number' => 'TMP-'.Str::uuid(),
                'customer_id' => $data['customer_id'],
                'assigned_to' => $data['assigned_to'] ?? null,
                'created_by' => $userId,
                'status' => $data['status'] ?? 'draft',
                'priority' => $data['priority'] ?? 'normal',
                'order_date' => $data['order_date'],
                'tax_rate' => $data['tax_rate'] ?? 10,
                'discount_cents' => $data['discount_cents'] ?? 0,
            ]);
            $order->save();
            $order->order_number = 'ORD-'.now()->format('Y').'-'.str_pad((string) $order->id, 6, '0', STR_PAD_LEFT);
            $order->save();
            $this->syncItems($order, $data['items']);
            $this->recalculate($order);

            if ($order->status === 'confirmed') {
                $this->deductStock($order);
            }

            OrderStatusHistory::create([
                'order_id' => $order->id,
                'user_id' => $userId,
                'from_status' => null,
                'to_status' => $order->status,
                'remarks' => $data['status_remarks'] ?? null,
            ]);
            $this->audit($order, $userId, 'order.created', null, $order->toArray());

            return $order->load(['customer', 'assignee', 'items.product', 'statusHistory.user', 'notes.user']);
        });
    }

    public function changeStatus(Order $order, string $status, ?string $remarks, int $userId): Order
    {
        return DB::transaction(function () use ($order, $status, $remarks, $userId) {
            $order = Order::with('items')->lockForUpdate()->findOrFail($order->id);
            $previous = $order->status;

            if (! in_array($status, self::TRANSITIONS[$previous] ?? [], true)) {
                throw ValidationException::withMessages([
                    'status' => "Transition from {$previous} to {$status} is not allowed.",
                ]);
            }

            if ($status === 'confirmed') {
                $this->deductStock($order);
            }
            if ($status === 'cancelled' && in_array($previous, ['confirmed', 'processing', 'ready'], true)) {
                $this->restoreStock($order);
            }

            $order->status = $status;
            $order->version++;
            $order->save();
            OrderStatusHistory::create([
                'order_id' => $order->id,
                'user_id' => $userId,
                'from_status' => $previous,
                'to_status' => $status,
                'remarks' => $remarks,
            ]);
            $this->audit($order, $userId, 'order.status_changed', ['status' => $previous], ['status' => $status]);

            return $order->load(['customer', 'assignee', 'items.product', 'statusHistory.user', 'notes.user']);
        });
    }

    public function update(Order $order, array $data, int $userId): Order
    {
        return DB::transaction(function () use ($order, $data, $userId) {
            $order = Order::with('items')->lockForUpdate()->findOrFail($order->id);
            if (in_array($order->status, ['completed', 'cancelled'], true)) {
                throw ValidationException::withMessages([
                    'order' => 'Terminal orders cannot be edited.',
                ]);
            }

            $oldFields = $order->only([
                'customer_id', 'assigned_to', 'priority', 'order_date',
                'tax_rate', 'discount_cents',
            ]);
            $oldItems = $order->items->map(fn ($item) => [
                'product_id' => $item->product_id,
                'quantity' => $item->quantity,
                'unit_price_cents' => $item->unit_price_cents,
                'discount_cents' => $item->discount_cents,
            ])->all();
            $syncItems = array_key_exists('items', $data) || array_key_exists('tax_rate', $data);
            $stockCommitted = in_array($order->status, ['confirmed', 'processing', 'ready'], true);

            if ($syncItems && $stockCommitted) {
                $this->restoreStock($order);
            }

            $order->fill(Arr::only($data, [
                'customer_id', 'assigned_to', 'priority', 'order_date',
                'tax_rate', 'discount_cents',
            ]));
            $order->version++;
            $order->save();

            if ($syncItems) {
                $items = $data['items'] ?? array_map(
                    fn ($item) => [
                        'product_id' => $item['product_id'],
                        'quantity' => $item['quantity'],
                        'unit_price_cents' => $item['unit_price_cents'],
                        'discount_cents' => $item['discount_cents'],
                    ],
                    $oldItems,
                );
                $this->syncItems($order, $items, ! array_key_exists('items', $data));
            }

            $this->recalculate($order);
            if ($syncItems && $stockCommitted) {
                $this->deductStock($order);
            }

            $this->audit(
                $order,
                $userId,
                'order.updated',
                ['fields' => $oldFields, 'items' => $oldItems],
                [
                    'fields' => $order->only([
                        'customer_id', 'assigned_to', 'priority', 'order_date',
                        'tax_rate', 'discount_cents',
                    ]),
                    'items' => $order->items->map(fn ($item) => [
                        'product_id' => $item->product_id,
                        'quantity' => $item->quantity,
                        'unit_price_cents' => $item->unit_price_cents,
                        'discount_cents' => $item->discount_cents,
                    ])->all(),
                ],
            );

            return $order->load(['customer', 'assignee', 'items.product', 'statusHistory.user', 'notes.user']);
        });
    }

    private function syncItems(Order $order, array $items, bool $preserveUnitPrices = false): void
    {
        $order->items()->delete();
        $order->unsetRelation('items');
        foreach ($items as $item) {
            $product = Product::lockForUpdate()->findOrFail($item['product_id']);
            $quantity = (int) $item['quantity'];
            $unitPrice = (int) ($preserveUnitPrices
                ? ($item['unit_price_cents'] ?? $product->price_cents)
                : $product->price_cents);
            $lineSubtotal = $unitPrice * $quantity;
            $discount = (int) ($item['discount_cents'] ?? 0);
            if ($discount > $lineSubtotal) {
                throw ValidationException::withMessages([
                    'items' => "The discount for {$product->name} cannot exceed the line subtotal.",
                ]);
            }
            $tax = intdiv(max(0, $lineSubtotal - $discount) * (int) ($order->tax_rate), 100);

            $order->items()->create([
                'product_id' => $product->id,
                'quantity' => $quantity,
                'unit_price_cents' => $unitPrice,
                'discount_cents' => $discount,
                'tax_cents' => $tax,
                'line_total_cents' => max(0, $lineSubtotal - $discount) + $tax,
            ]);
        }
    }

    private function recalculate(Order $order): void
    {
        $order->load('items');
        $subtotal = $order->items->sum(fn ($item) => $item->unit_price_cents * $item->quantity);
        $itemDiscount = $order->items->sum('discount_cents');
        $tax = $order->items->sum('tax_cents');
        if ($itemDiscount + $order->discount_cents > $subtotal) {
            throw ValidationException::withMessages([
                'discount_cents' => 'The combined discounts cannot exceed the order subtotal.',
            ]);
        }

        $order->subtotal_cents = $subtotal;
        $order->tax_cents = $tax;
        $order->total_cents = $subtotal - $itemDiscount - $order->discount_cents + $tax;
        $order->save();
    }

    private function deductStock(Order $order): void
    {
        foreach ($order->items as $item) {
            $product = Product::lockForUpdate()->findOrFail($item->product_id);
            if ($product->stock_quantity < $item->quantity) {
                throw ValidationException::withMessages([
                    'items' => "Insufficient stock for {$product->name}.",
                ]);
            }
            $product->decrement('stock_quantity', $item->quantity);
        }
    }

    private function restoreStock(Order $order): void
    {
        foreach ($order->items as $item) {
            Product::whereKey($item->product_id)->lockForUpdate()->increment('stock_quantity', $item->quantity);
        }
    }

    private function audit(Order $order, int $userId, string $action, ?array $old, ?array $new): void
    {
        AuditLog::create([
            'user_id' => $userId,
            'action' => $action,
            'auditable_type' => $order->getMorphClass(),
            'auditable_id' => $order->id,
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => request()->ip(),
        ]);
    }
}
