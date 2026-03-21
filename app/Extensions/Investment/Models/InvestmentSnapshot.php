<?php

declare(strict_types=1);

namespace FireflyIII\Extensions\Investment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A price snapshot for an InvestmentPosition at a given date.
 *
 * @property int    $id
 * @property int    $position_id
 * @property string $current_price  Decimal string (e.g. "150.00")
 * @property string $snapshot_date  Date string (Y-m-d)
 */
class InvestmentSnapshot extends Model
{
    protected $table    = 'investment_snapshots';

    protected $fillable = [
        'position_id',
        'current_price',
        'snapshot_date',
    ];

    public function position(): BelongsTo
    {
        return $this->belongsTo(InvestmentPosition::class, 'position_id');
    }

    protected function casts(): array
    {
        return [
            'created_at'    => 'datetime',
            'updated_at'    => 'datetime',
            'position_id'   => 'integer',
            'snapshot_date' => 'date',
        ];
    }
}
