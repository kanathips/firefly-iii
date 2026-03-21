<?php

declare(strict_types=1);

namespace FireflyIII\Extensions\AI\Models;

use FireflyIII\Extensions\AI\Enums\AIProvider;
use FireflyIII\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AI provider settings for a single user.
 *
 * The api_key column stores an encrypted ciphertext; decryption is handled by
 * the repository layer using Laravel's Crypt facade so the plaintext never
 * appears in model property access.
 *
 * @property int         $id
 * @property int         $user_id
 * @property AIProvider  $provider
 * @property string      $api_key      Encrypted ciphertext
 * @property bool        $is_enabled
 * @property null|string $deleted_at
 */
class AISettings extends Model
{
    use SoftDeletes;

    protected $table    = 'extension_ai_settings';

    protected $fillable = [
        'user_id',
        'provider',
        'api_key',
        'is_enabled',
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
            'deleted_at' => 'datetime',
            'user_id'    => 'integer',
            'provider'   => AIProvider::class,
            'is_enabled' => 'boolean',
        ];
    }
}
