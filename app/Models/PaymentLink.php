<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** A reusable, shareable link/QR: each visit creates a fresh order for the merchant. */
class PaymentLink extends Model
{
    public const DEFAULT_COLOR = '#2563eb';

    protected $fillable = ['title', 'amount', 'note', 'payment_account_id', 'is_active', 'description', 'button_label', 'theme_color'];

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

    public function buttonLabel(): string
    {
        return $this->button_label ?: 'Pay Now';
    }

    public function themeColor(): string
    {
        return $this->theme_color ?: self::DEFAULT_COLOR;
    }

    /** HTML a merchant pastes into their own website to show a Pay button. */
    public function buttonSnippet(): string
    {
        return '<script src="'.asset('js/upi-button.js').'" data-page="'.$this->code
            .'" data-label="'.e($this->buttonLabel()).'" data-color="'.$this->themeColor().'" async></script>';
    }

    public function isFixedAmount(): bool
    {
        return $this->amount !== null;
    }
}
