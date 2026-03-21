<?php

declare(strict_types=1);

namespace FireflyIII\Extensions\Investment\Models;

use FireflyIII\Models\Account;
use FireflyIII\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * An investment position owned by a user, linked to an asset Account.
 * Tracks quantity of shares/units and average cost basis per unit.
 *
 * @property int         $id
 * @property int         $user_id
 * @property int         $account_id
 * @property string      $symbol
 * @property string      $quantity      Decimal string (e.g. "10.00")
 * @property string      $avg_cost      Average cost per unit, decimal string
 * @property null|string $notes
 * @property InvestmentSnapshot|null $latestSnapshot
 */
class InvestmentPosition extends Model
{
    protected $table    = 'investment_positions';

    protected $fillable = [
        'user_id',
        'account_id',
        'symbol',
        'quantity',
        'avg_cost',
        'notes',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(InvestmentSnapshot::class, 'position_id');
    }

    /**
     * The single most recent snapshot, ordered by snapshot_date DESC.
     */
    public function latestSnapshot(): HasOne
    {
        return $this->hasOne(InvestmentSnapshot::class, 'position_id')
            ->latestOfMany('snapshot_date');
    }

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'user_id'    => 'integer',
            'account_id' => 'integer',
        ];
    }
}
