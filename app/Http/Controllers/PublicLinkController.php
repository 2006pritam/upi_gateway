<?php

namespace App\Http\Controllers;

use App\Models\PaymentLink;
use App\Services\OrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PublicLinkController extends Controller
{
    public function __construct(private OrderService $orders) {}

    public function show(PaymentLink $link)
    {
        return view('links.public', ['link' => $this->live($link)->load('user')]);
    }

    /** Every visit that submits the form gets its own fresh order, then goes to the normal checkout page. */
    public function pay(Request $request, PaymentLink $link)
    {
        $link = $this->live($link);

        $data = $request->validate([
            'amount' => [$link->isFixedAmount() ? 'prohibited' : 'required', 'numeric', 'min:1', 'max:100000', 'decimal:0,2'],
            'customer_name' => ['nullable', 'string', 'max:100'],
            'customer_mobile' => ['nullable', 'string', 'max:20'],
        ]);

        $order = $this->orders->create($link->user, [
            'amount' => $link->amount ?? $data['amount'],
            'client_order_id' => 'LNK'.strtoupper(Str::random(14)),
            'payment_account_id' => $link->payment_account_id,
            'customer_name' => $data['customer_name'] ?? null,
            'customer_mobile' => $data['customer_mobile'] ?? null,
            'note' => $link->note ?: Str::limit($link->title, 50, ''),
            'payment_link_id' => $link->id,
            'created_ip' => $request->ip(),
        ]);

        return redirect()->route('pay.show', $order);
    }

    private function live(PaymentLink $link): PaymentLink
    {
        abort_unless($link->is_active && $link->user?->is_active, 404);

        return $link;
    }
}
