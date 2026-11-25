<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $customers = Customer::query()
            ->when($request->search, fn ($q, $search) => $q->where(fn ($searchQuery) => $searchQuery
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->when($request->filled('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return response()->json(['success' => true, 'data' => $customers]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->role === 'admin', 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:255', 'unique:customers,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:1000'],
        ]);
        $customer = Customer::create($data);

        return response()->json(['success' => true, 'message' => 'Customer created', 'data' => $customer], 201);
    }

    public function show(Customer $customer)
    {
        $customer->loadCount(['orders', 'orders as pending_orders_count' => fn ($q) => $q->whereIn('status', ['draft', 'pending']), 'orders as completed_orders_count' => fn ($q) => $q->where('status', 'completed'), 'orders as cancelled_orders_count' => fn ($q) => $q->where('status', 'cancelled')]);
        $customer->setAttribute('total_order_value_cents', $customer->orders()->sum('total_cents'));
        $customer->load(['orders' => fn ($q) => $q->latest('order_date')->limit(5)->with('assignee')]);

        return response()->json(['success' => true, 'data' => $customer]);
    }

    public function update(Request $request, Customer $customer)
    {
        abort_unless($request->user()->role === 'admin', 403);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'email' => ['sometimes', 'email', 'max:255', 'unique:customers,email,'.$customer->id],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $customer->update($data);

        return response()->json(['success' => true, 'message' => 'Customer updated', 'data' => $customer]);
    }

    public function destroy(Request $request, Customer $customer)
    {
        abort_unless($request->user()->role === 'admin', 403);
        if ($customer->orders()->exists()) {
            $customer->update(['is_active' => false]);

            return response()->json(['success' => true, 'message' => 'Customer deactivated', 'data' => $customer]);
        }
        $customer->delete();

        return response()->json(['success' => true, 'message' => 'Customer deleted', 'data' => null]);
    }
}
