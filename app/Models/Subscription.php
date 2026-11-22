<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    public const INTERVALS = ['weekly' => 'Every week', 'monthly' => 'Every month', 'yearly' => 'Every year'];

    protected $fillable = ['plan_name', 'amount', 'interval', 'customer_name', 'customer_email', 'customer_mobile', 'next_due_at', 'status', 'payment_account_id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'next_due_at' => 'date', 'last_billed_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lastOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'last_order_id');
    }

    /** The date after $from for this interval (monthly keeps day-of-month where possible: 31 Jan -> 28 Feb). */
    public function advance(\Carbon\CarbonInterface $from): \Carbon\Carbon
    {
        $d = \Carbon\Carbon::parse($from)->startOfDay();

        return match ($this->interval) {
            'weekly' => $d->addWeek(),
            'yearly' => $d->addYearNoOverflow(),
            default => $d->addMonthNoOverflow(),
        };
    }
}
