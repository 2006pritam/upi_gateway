<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailPayment extends Model
{
    public const MATCHED = 'matched';

    public const UNMATCHED = 'unmatched';

    public const AMBIGUOUS = 'ambiguous';

    protected $fillable = [
        'user_id',
        'payment_account_id',
        'order_id',
        'message_id',
        'utr',
        'txn_id',
        'amount',
        'payer_name',
        'purpose',
        'paid_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    public function paymentAccount(): BelongsTo
    {
        return $this->belongsTo(PaymentAccount::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
