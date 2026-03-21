<?php

declare(strict_types=1);

namespace FireflyIII\Extensions\GoogleSheets\Models;

use FireflyIII\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Stores a user's Google Sheets OAuth2 connection and sync configuration.
 *
 * @property int         $id
 * @property int         $user_id
 * @property string      $google_token   JSON, encrypted at-rest
 * @property string      $sheet_id       Google Spreadsheet ID
 * @property string      $field_map      JSON mapping Firefly fields → sheet column letters
 * @property string      $direction      'import', 'export', or 'bidirectional'
 * @property bool        $is_active
 * @property null|string $last_synced_at
 */
class SheetConnection extends Model
{
    use SoftDeletes;

    protected $table = 'extension_sheet_connections';

    protected $fillable = [
        'user_id',
        'google_token',
        'sheet_id',
        'field_map',
        'direction',
        'is_active',
        'last_synced_at',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(SheetSyncLog::class);
    }

    /**
     * Decode field_map from JSON to array.
     *
     * @return array<string, string>
     */
    public function getDecodedFieldMap(): array
    {
        return (array) json_decode($this->field_map, true, 512, JSON_THROW_ON_ERROR);
    }

    protected function casts(): array
    {
        return [
            'created_at'     => 'datetime',
            'updated_at'     => 'datetime',
            'deleted_at'     => 'datetime',
            'last_synced_at' => 'datetime',
            'user_id'        => 'integer',
            'is_active'      => 'boolean',
        ];
    }
}
