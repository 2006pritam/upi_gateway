<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    public const PENDING = 'pending';
    public const PAID = 'paid';
    public const EXPIRED = 'expired';
    public const REVIEW = 'review'; // customer submitted a UTR, merchant must confirm

    // Customer UTR submissions allowed per order, and how long after expiry
    // a customer may still submit one (they may have paid just before expiry).
    public const MAX_UTR_ATTEMPTS = 3;
    public const UTR_GRACE_MINUTES = 60;

    protected $fillable = [
        'order_no',
        'payment_account_id',
        'client_order_id',
        'amount',
        'status',
        'customer_name',
        'customer_email',
        'customer_mobile',
        'note',
        'redirect_url',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'provider_response' => 'array',
            'paid_at' => 'datetime',
            'expires_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'utr_submitted_at' => 'datetime',
            'provider_checkout' => 'array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'order_no';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function paymentAccount(): BelongsTo
    {
        return $this->belongsTo(PaymentAccount::class);
    }

    public function webhookLogs(): HasMany
    {
        return $this->hasMany(WebhookLog::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    /**
     * Customers can submit a UTR only for manual-verification accounts, while
     * the order is pending or recently expired, and within the attempt limit.
     */
    public function acceptsCustomerUtr(): bool
    {
        return $this->paymentAccount->provider === 'manual'
            && $this->utr_attempts < self::MAX_UTR_ATTEMPTS
            && ($this->status === self::PENDING
                || ($this->status === self::EXPIRED && $this->expires_at->gt(now()->subMinutes(self::UTR_GRACE_MINUTES))));
    }

    public function isPastExpiry(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Standard UPI deep link. `tr` carries our order number so the provider
     * status API can find this exact payment.
     */
    public function upiIntentUrl(string $scheme = 'upi://pay'): string
    {
        $account = $this->paymentAccount;

        return $scheme.'?'.http_build_query([
            'pa' => $account->upi_id,
            'pn' => $account->payee_name,
            'am' => number_format((float) $this->amount, 2, '.', ''),
            'cu' => 'INR',
            'tr' => $this->order_no,
            'tn' => $this->note ?: $this->order_no,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function paymentUrl(): string
    {
        return route('pay.show', $this);
    }

    /**
     * Shape returned by the API and sent in webhooks.
     */
    public function toApiArray(): array
    {
        return [
            'order_id' => $this->order_no,
            'client_order_id' => $this->client_order_id,
            'amount' => $this->amount,
            'currency' => 'INR',
            'status' => $this->status,
            'utr' => $this->utr,
            'submitted_utr' => $this->status === self::REVIEW ? $this->customer_utr : null,
            'payment_url' => $this->paymentUrl(),
            // PG orders are paid on the PG's checkout, so there's no UPI link.
            'upi_intent' => $this->paymentAccount->isHosted() ? null : $this->upiIntentUrl(),
            'customer' => [
                'name' => $this->customer_name,
                'email' => $this->customer_email,
                'mobile' => $this->customer_mobile,
            ],
            'paid_at' => $this->paid_at?->toIso8601String(),
            'expires_at' => $this->expires_at->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
