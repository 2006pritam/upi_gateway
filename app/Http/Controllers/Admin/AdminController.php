<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    public function merchants()
    {
        $merchants = User::where('role', 'merchant')
            ->withCount(['orders', 'orders as paid_orders_count' => fn ($q) => $q->where('status', Order::PAID)])
            ->withSum(['orders as paid_amount' => fn ($q) => $q->where('status', Order::PAID)], 'amount')
            ->latest()
            ->paginate(25);

        return view('admin.merchants', compact('merchants'));
    }

    public function toggleMerchant(Request $request, User $user)
    {
        abort_if($user->isAdmin(), 403);

        $user->update(['is_active' => ! $user->is_active]);

        // Audit log
        AdminAuditLog::create([
            'admin_id'     => $request->user()->id,
            'action'       => $user->is_active ? 'merchant_enabled' : 'merchant_disabled',
            'subject_type' => 'user',
            'subject_id'   => $user->id,
            'meta'         => ['email' => $user->email],
            'ip'           => $request->ip(),
        ]);

        return back()->with('status', "{$user->displayName()} ".($user->is_active ? 'enabled.' : 'disabled.'));
    }

    public function orders(Request $request)
    {
        $query = Order::with('user', 'paymentAccount')->latest();

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return view('admin.orders', ['orders' => $query->paginate(30)->withQueryString()]);
    }

    /**
     * Admin view of a specific merchant order — read-only.
     * Logs the access for audit trail.
     */
    public function showOrder(Request $request, Order $order)
    {
        AdminAuditLog::create([
            'admin_id'     => $request->user()->id,
            'action'       => 'order_viewed',
            'subject_type' => 'order',
            'subject_id'   => $order->id,
            'meta'         => ['order_no' => $order->order_no, 'merchant_id' => $order->user_id],
            'ip'           => $request->ip(),
        ]);

        return view('orders.show', [
            'order'    => $order->load('paymentAccount', 'webhookLogs', 'user'),
            'readOnly' => true,
        ]);
    }
}
