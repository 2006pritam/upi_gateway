<?php

namespace App\Models;

use App\Services\Verifiers\FamEmailVerifier;
use App\Services\Verifiers\FamPayEmailVerifier;
use App\Services\Verifiers\ManualVerifier;
use App\Services\Verifiers\PaytmBusinessVerifier;
use App\Services\Verifiers\PaytmPgVerifier;
use App\Services\Verifiers\PhonePePgVerifier;
use App\Services\Verifiers\Verifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentAccount extends Model
{
    /**
     * Supported providers and the verifier that confirms payments for each.
     * To add a new provider, write a Verifier and register it here.
     */
    public const PROVIDERS = [
        'paytm_business' => ['label' => 'Paytm for Business (auto verify)', 'verifier' => PaytmBusinessVerifier::class, 'hosted' => false],
        'fampay_email' => ['label' => 'FamPay (email auto verify)', 'verifier' => FamPayEmailVerifier::class, 'hosted' => false],
        'phonepe_pg' => ['label' => 'PhonePe PG (official)', 'verifier' => PhonePePgVerifier::class, 'hosted' => true],
        'paytm_pg' => ['label' => 'Paytm PG (official)', 'verifier' => PaytmPgVerifier::class, 'hosted' => true],
        'fam_email' => ['label' => 'FamPay / FamX (auto verify by e-mail)', 'verifier' => FamEmailVerifier::class, 'hosted' => false],
        'manual' => ['label' => 'Any UPI ID (manual verify)', 'verifier' => ManualVerifier::class, 'hosted' => false],
    ];

    protected $fillable = [
        'provider',
        'label',
        'upi_id',
        'payee_name',
        'credentials',
        'is_active',
        'is_default',
        'daily_limit',
    ];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'daily_limit' => 'decimal:2',
            'unhealthy_until' => 'datetime',
        ];
    }

    /**
     * Adds `used_today`: amount paid today plus open pending and in-review orders, i.e.
     * what counts against the daily limit.
     */
    public function scopeWithUsageToday(Builder $query): void
    {
        $query->withSum(['orders as used_today' => fn ($q) => $q->where(fn ($q) => $q
            ->where(fn ($q) => $q->where('status', Order::PAID)->where('paid_at', '>=', today()))
            ->orWhere(fn ($q) => $q->where('status', Order::PENDING)->where('expires_at', '>', now()))
            ->orWhere('status', Order::REVIEW))], 'amount');
    }

    public function usedToday(): float
    {
        return (float) ($this->used_today ?? 0);
    }

    public function remainingToday(): ?float
    {
        return $this->daily_limit === null ? null : max(0, (float) $this->daily_limit - $this->usedToday());
    }

    public function canAccept(float $amount): bool
    {
        return $this->daily_limit === null || $this->usedToday() + $amount <= (float) $this->daily_limit;
    }

    public const FAILURE_LIMIT = 5;

    public const COOLDOWN_MINUTES = 10;

    /** False while the account is cooling down after repeated provider errors. */
    public function isHealthy(): bool
    {
        return $this->unhealthy_until === null || $this->unhealthy_until->isPast();
    }

    public function recordCheckFailure(): void
    {
        $count = $this->failure_count + 1;
        $this->forceFill([
            'failure_count' => $count,
            'unhealthy_until' => $count >= self::FAILURE_LIMIT ? now()->addMinutes(self::COOLDOWN_MINUTES) : $this->unhealthy_until,
        ])->saveQuietly();
    }

    public function recordCheckSuccess(): void
    {
        if ($this->failure_count > 0 || $this->unhealthy_until !== null) {
            $this->forceFill(['failure_count' => 0, 'unhealthy_until' => null])->saveQuietly();
        }
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function providerLabel(): string
    {
        return self::PROVIDERS[$this->provider]['label'] ?? $this->provider;
    }

    /**
     * Official PGs: the customer pays on the PG's checkout, not our UPI QR.
     */
    public function isHosted(): bool
    {
        return self::PROVIDERS[$this->provider]['hosted'] ?? false;
    }

    public function environment(): ?string
    {
        return $this->isHosted() ? ($this->credential('environment') === 'production' ? 'production' : 'sandbox') : null;
    }

    public function verifier(): Verifier
    {
        return app(self::PROVIDERS[$this->provider]['verifier']);
    }

    public function credential(string $key): ?string
    {
        return $this->credentials[$key] ?? null;
    }
}
