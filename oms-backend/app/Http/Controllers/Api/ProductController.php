<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $products = Product::query()
            ->when($request->search, fn ($q, $search) => $q->where(fn ($searchQuery) => $searchQuery
                ->where('name', 'like', "%{$search}%")
                ->orWhere('sku', 'like', "%{$search}%")))
            ->when($request->filled('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return response()->json(['success' => true, 'data' => $products]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->role === 'admin', 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'sku' => ['required', 'string', 'max:50', 'unique:products,sku'],
            'price_cents' => ['required', 'integer', 'min:0'],
            'stock_quantity' => ['required', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $product = Product::create($data);

        return response()->json(['success' => true, 'message' => 'Product created', 'data' => $product], 201);
    }

    public function show(Product $product)
    {
        return response()->json(['success' => true, 'data' => $product]);
    }

    public function update(Request $request, Product $product)
    {
        abort_unless($request->user()->role === 'admin', 403);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'sku' => ['sometimes', 'string', 'max:50', 'unique:products,sku,'.$product->id],
            'price_cents' => ['sometimes', 'integer', 'min:0'],
            'stock_quantity' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $product->update($data);

        return response()->json(['success' => true, 'message' => 'Product updated', 'data' => $product]);
    }

    public function destroy(Request $request, Product $product)
    {
        abort_unless($request->user()->role === 'admin', 403);
        if ($product->orderItems()->exists()) {
            $product->update(['is_active' => false]);

            return response()->json(['success' => true, 'message' => 'Product deactivated', 'data' => $product]);
        }
        $product->delete();

        return response()->json(['success' => true, 'message' => 'Product deleted', 'data' => null]);
    }
}
