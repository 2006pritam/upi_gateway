<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** A reusable, shareable link/QR: each visit creates a fresh order for the merchant. */
class PaymentLink extends Model
{
    protected $fillable = ['title', 'amount', 'note', 'payment_account_id', 'is_active'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(function (PaymentLink $link) {
            $link->code ??= strtolower(Str::random(10));
        });
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function publicUrl(): string
    {
        return route('link.show', $this->code);
    }

    public function isFixedAmount(): bool
    {
        return $this->amount !== null;
    }
}
