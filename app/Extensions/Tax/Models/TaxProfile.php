<?php

declare(strict_types=1);

namespace FireflyIII\Extensions\Tax\Models;

use FireflyIII\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tax profile owned by a user. One user may have many profiles (e.g. one per
 * tax year or per income type).
 *
 * @property int         $id
 * @property int         $user_id
 * @property string      $name
 * @property int         $tax_year
 * @property float       $tax_rate
 * @property null|string $notes
 */
class TaxProfile extends Model
{
    use SoftDeletes;

    protected $table    = 'extension_tax_profiles';

    protected $fillable = [
        'user_id',
        'name',
        'tax_year',
        'tax_rate',
        'notes',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function taxDeductibleTags(): HasMany
    {
        return $this->hasMany(TaxDeductibleTag::class);
    }

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
            'user_id'    => 'integer',
            'tax_year'   => 'integer',
            'tax_rate'   => 'float',
        ];
    }
}
