<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
        'sandbox_webhook_url',
        'rotation_mode',
        'redirect_domains',
    ];

    public const ROTATION_MODES = [
        'random' => 'Random rotation',
        'default' => 'Default account first',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'api_secret',
        'sandbox_api_secret',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'api_secret' => 'encrypted',
            'sandbox_api_secret' => 'encrypted',
            'redirect_domains' => 'array',
            'api_last_used_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (User $user) {
            if ($user->role === 'admin' && Schema::hasTable('installation_guards')) {
                DB::table('installation_guards')->insertOrIgnore([
                    'name' => 'primary_admin',
                    'user_id' => $user->id,
                    'created_at' => now(),
                ]);
            }
        });
    }

    public function paymentAccounts(): HasMany
    {
        return $this->hasMany(PaymentAccount::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function adminAuditLogs(): HasMany
    {
        return $this->hasMany(AdminAuditLog::class, 'admin_id');
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
     * Does NOT touch the password — session invalidation for API-key rotation is
     * handled separately via logoutOtherDevices on the password-change endpoint.
     */
    public function regenerateApiCredentials(): void
    {
        $this->api_key = 'key_'.Str::random(24);
        $this->api_secret = 'sec_'.Str::random(40);
        $this->save();
    }

    /** @return array{key: string, secret: string} */
    public function regenerateSandboxApiCredentials(): array
    {
        $key = 'test_key_'.Str::random(24);
        $secret = 'test_sec_'.Str::random(40);

        $this->forceFill([
            'sandbox_api_key' => $key,
            'sandbox_api_secret' => $secret,
        ])->save();

        return ['key' => $key, 'secret' => $secret];
    }

    public function revokeSandboxApiCredentials(): void
    {
        $this->forceFill([
            'sandbox_api_key' => null,
            'sandbox_api_secret' => null,
        ])->save();
    }

    /**
     * Returns the list of allowed redirect domains for this merchant.
     *
     * @return string[]
     */
    public function allowedRedirectDomains(): array
    {
        return array_filter((array) ($this->redirect_domains ?? []));
    }

    /**
     * Check whether a given redirect URL's host is allowed for this merchant.
     * Exact match OR subdomain match.
     */
    public function isRedirectUrlAllowed(string $url): bool
    {
        $allowed = $this->allowedRedirectDomains();
        if (empty($allowed)) {
            return false;
        }

        $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
        if ($host === '') {
            return false;
        }

        foreach ($allowed as $domain) {
            $domain = strtolower(trim($domain));
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return true;
            }
        }

        return false;
    }
}
