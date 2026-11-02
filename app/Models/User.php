<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $attributes = [
        'role' => 'merchant',
        'is_active' => true,
        'rotation_mode' => 'random',
    ];

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'business_name',
        'phone',
        'is_active',
        'webhook_url',
        'rotation_mode',
    ];

    public const ROTATION_MODES = [
        'random' => 'Random rotation',
        'default' => 'Default account first',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'api_secret',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'api_secret' => 'encrypted',
        ];
    }

    public function paymentAccounts(): HasMany
    {
        return $this->hasMany(PaymentAccount::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function displayName(): string
    {
        return $this->business_name ?: $this->name;
    }

    /**
     * Issue a fresh API key/secret pair. The old pair stops working immediately.
     */
    public function regenerateApiCredentials(): void
    {
        $this->api_key = 'key_'.Str::random(24);
        $this->api_secret = 'sec_'.Str::random(40);
        $this->save();
    }
}
