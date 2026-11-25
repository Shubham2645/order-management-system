<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->role, ['admin', 'manager'], true);
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['sometimes', Rule::exists('customers', 'id')->where('is_active', true)],
            'assigned_to' => [
                'sometimes',
                'nullable',
                Rule::exists('users', 'id')->where(fn ($query) => $query->where('role', 'staff')->where('is_active', true)),
            ],
            'priority' => ['sometimes', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'order_date' => ['sometimes', 'date'],
            'tax_rate' => ['sometimes', 'integer', 'between:0,100'],
            'discount_cents' => ['sometimes', 'integer', 'min:0'],
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.product_id' => ['required_with:items', Rule::exists('products', 'id')->where('is_active', true)],
            'items.*.quantity' => ['required_with:items', 'integer', 'min:1'],
            'items.*.discount_cents' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
