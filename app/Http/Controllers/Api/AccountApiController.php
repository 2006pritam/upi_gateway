<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentAccount;
use Illuminate\Http\Request;

class AccountApiController extends Controller
{
    /**
     * The merchant's UPI accounts, so API clients know which
     * `payment_account_id` values exist and how much room is left today.
     */
    public function index(Request $request)
    {
        $merchant = $request->user();

        $accounts = $merchant->paymentAccounts()->withUsageToday()->oldest()->get()
            ->map(fn (PaymentAccount $a) => [
                'id' => $a->id,
                'label' => $a->label,
                'upi_id' => $a->upi_id,
                'provider' => $a->provider,
                'is_active' => $a->is_active,
                'is_default' => $a->is_default,
                'daily_limit' => $a->daily_limit,
                'used_today' => number_format($a->usedToday(), 2, '.', ''),
                'remaining_today' => $a->remainingToday() === null ? null : number_format($a->remainingToday(), 2, '.', ''),
            ]);

        return response()->json(['success' => true, 'rotation_mode' => $merchant->rotation_mode, 'data' => $accounts]);
    }
}
