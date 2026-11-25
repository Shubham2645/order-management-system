<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'group_by' => ['nullable', Rule::in(['day', 'week', 'month'])],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
        ]);
        $groupBy = $filters['group_by'] ?? 'day';
        $toDate = $filters['to_date'] ?? now()->toDateString();
        $fromDate = $filters['from_date'] ?? Carbon::parse($toDate)->subDays(29)->toDateString();
        $orders = Order::query()
            ->where('order_date', '>=', $fromDate)
            ->where('order_date', '<=', $toDate);
        $statusCounts = (clone $orders)
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status');

        $groupExpression = $this->groupExpression($groupBy);
        $completedOrders = (clone $orders)->where('status', 'completed');
        $trend = (clone $completedOrders)
            ->selectRaw("{$groupExpression} as period, SUM(total_cents) as revenue_cents")
            ->groupBy('period')
            ->orderBy('period')
            ->get();
        $totalRevenue = (clone $completedOrders)->sum('total_cents');
        $averageOrderValue = (clone $completedOrders)->avg('total_cents');

        return response()->json([
            'success' => true,
            'data' => [
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'group_by' => $groupBy,
                'total_orders' => (clone $orders)->count(),
                'pending_orders' => $statusCounts['pending'] ?? 0,
                'confirmed_orders' => $statusCounts['confirmed'] ?? 0,
                'processing_orders' => $statusCounts['processing'] ?? 0,
                'ready_orders' => $statusCounts['ready'] ?? 0,
                'completed_orders' => $statusCounts['completed'] ?? 0,
                'cancelled_orders' => $statusCounts['cancelled'] ?? 0,
                'total_revenue_cents' => (int) $totalRevenue,
                'average_order_value_cents' => (int) ($averageOrderValue ?? 0),
                'total_customers' => Customer::count(),
                'total_products' => Product::where('is_active', true)->count(),
                'status_distribution' => $statusCounts,
                'revenue_trend' => $trend,
            ],
        ]);
    }

    private function groupExpression(string $groupBy): string
    {
        $driver = DB::connection()->getDriverName();

        return match ([$driver, $groupBy]) {
            ['mysql', 'day'], ['pgsql', 'day'], ['sqlite', 'day'] => 'DATE(order_date)',
            ['mysql', 'week'] => "DATE_FORMAT(order_date, '%x-W%v')",
            ['sqlite', 'week'] => "strftime('%Y-W%W', order_date)",
            ['pgsql', 'week'] => "to_char(order_date, 'IYYY-\"W\"IW')",
            ['mysql', 'month'] => "DATE_FORMAT(order_date, '%Y-%m')",
            ['sqlite', 'month'] => "strftime('%Y-%m', order_date)",
            ['pgsql', 'month'] => "to_char(order_date, 'YYYY-MM')",
            default => throw new \LogicException("Dashboard grouping is not supported for the {$driver} database driver."),
        };
    }
}
