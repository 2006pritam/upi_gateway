<?php

namespace App\Models;

use App\Support\SubscriptionPlans;
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
        'subscription_status' => self::SUBSCRIPTION_PENDING,
    ];

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'business_name',
        'phone',
        'is_active',
        'subscription_status',
        'subscription_requested_plan',
        'subscription_plan',
        'subscription_approved_at',
        'subscription_paid_at',
        'subscription_started_at',
        'subscription_expires_at',
        'webhook_url',
        'sandbox_webhook_url',
        'rotation_mode',
        'redirect_domains',
    ];

    /** Extra endpoints allowed per environment (the primary URL is not counted). */
    public const MAX_EXTRA_WEBHOOKS = 4;

    public const SUBSCRIPTION_PENDING = 'pending_approval';

    public const SUBSCRIPTION_PAYMENT_DUE = 'payment_due';

    public const SUBSCRIPTION_ACTIVE = 'active';

    public const SUBSCRIPTION_EXPIRED = 'expired';

    public const SUBSCRIPTION_REJECTED = 'rejected';

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
            'subscription_approved_at' => 'datetime',
            'subscription_paid_at' => 'datetime',
            'subscription_started_at' => 'datetime',
            'subscription_expires_at' => 'datetime',
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

    /** Extra webhook URLs, on top of webhook_url / sandbox_webhook_url. */
    public function webhookEndpoints(): HasMany
    {
        return $this->hasMany(WebhookEndpoint::class);
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

    public function subscriptionHasExpired(): bool
    {
        return $this->subscription_status === self::SUBSCRIPTION_ACTIVE
            && $this->subscription_expires_at !== null
            && $this->subscription_expires_at->lessThanOrEqualTo(now());
    }

    public function hasSubscriptionAccess(): bool
    {
        return $this->subscription_status === self::SUBSCRIPTION_ACTIVE
            && ($this->subscription_expires_at === null || $this->subscription_expires_at->isFuture());
    }

    public function subscriptionPlanLabel(): string
    {
        return SubscriptionPlans::label($this->subscription_requested_plan ?: $this->subscription_plan);
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
