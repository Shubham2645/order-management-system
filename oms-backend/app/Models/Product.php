<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = ['name', 'sku', 'price_cents', 'stock_quantity', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'price_cents' => 'integer', 'stock_quantity' => 'integer'];
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
