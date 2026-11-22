<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** One named key/secret pair per website or app. The merchant's original keys stay valid as the "Default key". */
class ApiKey extends Model
{
    public const MAX_ACTIVE_PER_USER = 20;

    protected $fillable = ['name', 'environment', 'webhook_url'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return ['secret' => 'encrypted', 'last_used_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    /** @return array{0: ApiKey, 1: string} the saved key and its plain secret (shown to the merchant once) */
    public static function issue(User $user, string $name, string $environment, ?string $webhookUrl = null): array
    {
        $sandbox = $environment === Order::SANDBOX;
        $secret = ($sandbox ? 'test_sec_' : 'sec_').Str::random(40);

        $apiKey = new self(['name' => $name, 'environment' => $sandbox ? Order::SANDBOX : Order::LIVE, 'webhook_url' => $webhookUrl]);
        $apiKey->user_id = $user->id;
        $apiKey->key = ($sandbox ? 'test_key_' : 'key_').Str::random(24);
        $apiKey->secret = $secret;
        $apiKey->save();

        return [$apiKey, $secret];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }
}
