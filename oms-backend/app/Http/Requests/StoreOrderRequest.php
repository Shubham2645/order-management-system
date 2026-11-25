<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->role, ['admin', 'manager'], true);
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', Rule::exists('customers', 'id')->where('is_active', true)],
            'assigned_to' => [
                'nullable',
                Rule::exists('users', 'id')->where(fn ($query) => $query->where('role', 'staff')->where('is_active', true)),
            ],
            'status' => ['nullable', Rule::in(['draft', 'pending', 'confirmed'])],
            'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'order_date' => ['required', 'date'],
            'tax_rate' => ['nullable', 'integer', 'between:0,100'],
            'discount_cents' => ['nullable', 'integer', 'min:0'],
            'status_remarks' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', Rule::exists('products', 'id')->where('is_active', true)],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.discount_cents' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
