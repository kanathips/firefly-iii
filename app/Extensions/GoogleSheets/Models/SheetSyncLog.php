<?php

declare(strict_types=1);

namespace FireflyIII\Extensions\GoogleSheets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Records one sync run for a SheetConnection.
 *
 * @property int         $id
 * @property int         $sheet_connection_id
 * @property string      $status         'completed', 'failed', 'in_progress'
 * @property int         $records_in     rows read from the sheet
 * @property int         $records_out    rows written to the sheet
 * @property null|string $error_message
 * @property null|string $started_at
 * @property null|string $finished_at
 */
class SheetSyncLog extends Model
{
    protected $table = 'extension_sheet_sync_logs';

    protected $fillable = [
        'sheet_connection_id',
        'status',
        'records_in',
        'records_out',
        'error_message',
        'started_at',
        'finished_at',
    ];

    public function sheetConnection(): BelongsTo
    {
        return $this->belongsTo(SheetConnection::class);
    }

    protected function casts(): array
    {
        return [
            'created_at'         => 'datetime',
            'updated_at'         => 'datetime',
            'started_at'         => 'datetime',
            'finished_at'        => 'datetime',
            'sheet_connection_id' => 'integer',
            'records_in'         => 'integer',
            'records_out'        => 'integer',
        ];
    }
}
