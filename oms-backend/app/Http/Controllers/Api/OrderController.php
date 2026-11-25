<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Requests\UpdateOrderRequest;
use App\Models\AuditLog;
use App\Models\Notification as NotificationModel;
use App\Models\Order;
use App\Models\OrderNote;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    public function __construct(private OrderService $orderService) {}

    public function index(Request $request)
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(['draft', 'pending', 'confirmed', 'processing', 'ready', 'completed', 'cancelled'])],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'assigned_to' => ['nullable', 'string', 'max:20'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
            'sort' => ['nullable', Rule::in(['order_date', 'total_cents', 'status', 'priority'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $orders = Order::query()
            ->with(['customer:id,name,email', 'assignee:id,name'])
            ->when($filters['search'] ?? null, function ($q, $search) {
                $q->where(function ($searchQuery) use ($search) {
                    $searchQuery->where('order_number', 'like', "%{$search}%")
                        ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"))
                        ->orWhereHas('assignee', fn ($u) => $u->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('items.product', fn ($p) => $p->where('name', 'like', "%{$search}%"));
                });
            })
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['priority'] ?? null, fn ($q, $priority) => $q->where('priority', $priority))
            ->when($filters['customer_id'] ?? null, fn ($q, $customerId) => $q->where('customer_id', $customerId))
            ->when(isset($filters['assigned_to']) && $filters['assigned_to'] !== '', function ($q) use ($filters) {
                if ($filters['assigned_to'] === 'unassigned') {
                    $q->whereNull('assigned_to');
                } else {
                    $q->where('assigned_to', $filters['assigned_to']);
                }
            })
            ->when($filters['from_date'] ?? null, fn ($q, $date) => $q->where('order_date', '>=', $date))
            ->when($filters['to_date'] ?? null, fn ($q, $date) => $q->where('order_date', '<=', $date))
            ->when($request->user()->role === 'staff', fn ($q) => $q->where('assigned_to', $request->user()->id))
            ->when(isset($filters['sort']),
                fn ($q) => $q->orderBy($filters['sort'], $filters['direction'] ?? 'desc'),
                fn ($q) => $q->latest('order_date'))
            ->paginate($filters['per_page'] ?? 15);

        return response()->json(['success' => true, 'data' => $orders]);
    }

    public function store(StoreOrderRequest $request)
    {
        $order = $this->orderService->create($request->validated(), $request->user()->id);

        return response()->json(['success' => true, 'message' => 'Order created successfully', 'data' => $order], 201);
    }

    public function show(Request $request, Order $order)
    {
        if ($request->user()->role === 'staff' && $order->assigned_to !== $request->user()->id) {
            abort(403);
        }
        $order->load(['customer', 'assignee', 'creator', 'items.product', 'statusHistory.user', 'notes.user']);
        $order->setAttribute('audits', AuditLog::where('auditable_type', $order->getMorphClass())->where('auditable_id', $order->id)->latest()->limit(50)->get());

        return response()->json(['success' => true, 'data' => $order]);
    }

    public function update(UpdateOrderRequest $request, Order $order)
    {
        $order = $this->orderService->update($order, $request->validated(), $request->user()->id);

        return response()->json(['success' => true, 'message' => 'Order updated', 'data' => $order]);
    }

    public function destroy(Request $request, Order $order)
    {
        abort_unless($request->user()->role === 'admin', 403);
        if ($order->status !== 'cancelled') {
            abort(422, 'Only cancelled orders can be deleted.');
        }
        $order->delete();

        return response()->json(['success' => true, 'message' => 'Order deleted', 'data' => null]);
    }

    public function changeStatus(Request $request, Order $order)
    {
        abort_unless(in_array($request->user()->role, ['admin', 'manager', 'staff'], true), 403);
        if ($request->user()->role === 'staff' && $order->assigned_to !== $request->user()->id) {
            abort(403);
        }
        $data = $request->validate(['status' => ['required', 'string'], 'remarks' => ['nullable', 'string', 'max:2000']]);
        $order = $this->orderService->changeStatus($order, $data['status'], $data['remarks'] ?? null, $request->user()->id);

        return response()->json(['success' => true, 'message' => 'Status updated', 'data' => $order]);
    }

    public function assign(Request $request, Order $order)
    {
        abort_unless(in_array($request->user()->role, ['admin', 'manager'], true), 403);
        $data = $request->validate([
            'assigned_to' => [
                'required',
                Rule::exists('users', 'id')->where(fn ($query) => $query->where('role', 'staff')->where('is_active', true)),
            ],
        ]);
        $old = $order->assigned_to;
        $order->update(['assigned_to' => $data['assigned_to'], 'version' => $order->version + 1]);
        NotificationModel::create([
            'user_id' => $data['assigned_to'], 'type' => 'order.assigned',
            'title' => 'New order assigned',
            'body' => "Order {$order->order_number} has been assigned to you.",
            'data' => ['order_id' => $order->id],
        ]);
        AuditLog::create([
            'user_id' => $request->user()->id, 'action' => 'order.assignment_changed',
            'auditable_type' => $order->getMorphClass(), 'auditable_id' => $order->id,
            'old_values' => ['assigned_to' => $old], 'new_values' => ['assigned_to' => $data['assigned_to']],
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['success' => true, 'message' => 'Order assigned', 'data' => $order->fresh('assignee')]);
    }

    public function assignableUsers(Request $request)
    {
        abort_unless(in_array($request->user()->role, ['admin', 'manager'], true), 403);
        $users = User::query()
            ->where('role', 'staff')
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return response()->json(['success' => true, 'data' => $users]);
    }

    public function addNote(Request $request, Order $order)
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);
        if ($request->user()->role === 'staff' && $order->assigned_to !== $request->user()->id) {
            abort(403);
        }
        $note = OrderNote::create(['order_id' => $order->id, 'user_id' => $request->user()->id, 'body' => $data['body']]);

        return response()->json(['success' => true, 'message' => 'Note added', 'data' => $note->load('user')], 201);
    }

    public function notifications(Request $request)
    {
        return response()->json(['success' => true, 'data' => NotificationModel::where('user_id', $request->user()->id)->latest()->paginate(20)]);
    }

    public function readNotification(Request $request, NotificationModel $notification)
    {
        abort_unless($notification->user_id === $request->user()->id, 403);
        $notification->update(['read_at' => now()]);

        return response()->json(['success' => true, 'data' => $notification]);
    }

    public function readAllNotifications(Request $request)
    {
        NotificationModel::where('user_id', $request->user()->id)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['success' => true, 'message' => 'Notifications marked as read', 'data' => null]);
    }
}
