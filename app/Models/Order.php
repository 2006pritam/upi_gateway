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

    public const FAILED = 'failed';

    public const LIVE = 'live';

    public const SANDBOX = 'sandbox';

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
            'sandbox_simulated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Order $order) {
            if ($order->expires_at === null) {
                $order->expires_at = now()->addMinutes((int) config('gateway.order_expiry_minutes', 15));
            }
        });
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
     * Customers can submit a UTR only for manual and e-mail verified accounts, while
     * the order is pending or recently expired, and within the attempt limit.
     * (For e-mail accounts it is the fallback when a payment could not be matched.)
     */
    public function acceptsCustomerUtr(): bool
    {
        return $this->environment === self::LIVE
            && in_array($this->paymentAccount?->provider, ['manual', 'fam_email'], true)
            && $this->utr_attempts < self::MAX_UTR_ATTEMPTS
            && ($this->status === self::PENDING
                || ($this->status === self::EXPIRED && $this->expires_at?->gt(now()->subMinutes(self::UTR_GRACE_MINUTES))));
    }

    public function isPastExpiry(): bool
    {
        return $this->expires_at?->isPast() ?? false;
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
        return $this->environment === self::SANDBOX
            ? route('sandbox.pay.show', $this)
            : route('pay.show', $this);
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
            'environment' => $this->environment,
            'test' => $this->environment === self::SANDBOX,
            'utr' => $this->utr,
            'submitted_utr' => $this->status === self::REVIEW ? $this->customer_utr : null,
            'payment_url' => $this->paymentUrl(),
            // PG orders are paid on the PG's checkout, so there's no UPI link.
            'upi_intent' => $this->environment === self::SANDBOX
                ? null
                : ($this->paymentAccount->isHosted() ? null : $this->upiIntentUrl()),
            'customer' => [
                'name' => $this->customer_name,
                'email' => $this->customer_email,
                'mobile' => $this->customer_mobile,
            ],
            'paid_at' => $this->paid_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
