<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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

    public function toggleMerchant(User $user)
    {
        abort_if($user->isAdmin(), 403);
        $user->update(['is_active' => ! $user->is_active]);

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
}
