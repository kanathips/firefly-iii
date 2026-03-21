<?php

declare(strict_types=1);

namespace FireflyIII\Extensions\Notifications\Models;

use FireflyIII\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A browser Web Push subscription for a user (VAPID).
 *
 * @property int         $id
 * @property int         $user_id
 * @property string      $endpoint    Push service endpoint URL
 * @property string      $p256dh_key  Client public key (base64url)
 * @property string      $auth_key    Authentication secret (base64url)
 * @property null|string $user_agent  Optional UA string for identification
 */
class PushSubscription extends Model
{
    protected $table    = 'push_subscriptions';

    protected $fillable = [
        'user_id',
        'endpoint',
        'p256dh_key',
        'auth_key',
        'user_agent',
    ];

    protected $hidden = [
        'p256dh_key',
        'auth_key',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'user_id'    => 'integer',
        ];
    }
}
