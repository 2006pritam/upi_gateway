<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An additional webhook URL for a merchant, in either the live or sandbox
 * environment. The merchant's primary URL lives on the users table.
 */
class WebhookEndpoint extends Model
{
    protected $fillable = ['user_id', 'environment', 'url'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
