<?php

declare(strict_types=1);

namespace FireflyIII\Extensions\Tax\Models;

use FireflyIII\Models\Tag;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pivot-like record that links a Tag to a TaxProfile so that all transactions
 * carrying that tag are considered tax-deductible under the profile.
 *
 * @property int $id
 * @property int $tax_profile_id
 * @property int $tag_id
 */
class TaxDeductibleTag extends Model
{
    protected $table    = 'extension_tax_tag_links';

    protected $fillable = [
        'tax_profile_id',
        'tag_id',
    ];

    public function taxProfile(): BelongsTo
    {
        return $this->belongsTo(TaxProfile::class);
    }

    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class);
    }

    protected function casts(): array
    {
        return [
            'created_at'     => 'datetime',
            'updated_at'     => 'datetime',
            'tax_profile_id' => 'integer',
            'tag_id'         => 'integer',
        ];
    }
}
