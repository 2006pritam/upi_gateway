<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A merchant paying a plan fee. The order belongs to the platform admin (it is
 * collected on the admin's UPI account); this row says who it is for. Only the
 * server writes these, so an order's client_order_id or note is never trusted.
 */
class PlanPayment extends Model
{
    protected $fillable = ['user_id', 'order_id', 'plan', 'amount', 'applied_at'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'applied_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
