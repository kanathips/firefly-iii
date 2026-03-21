<?php

declare(strict_types=1);

namespace FireflyIII\Extensions\AI\Models;

use FireflyIII\Models\TransactionJournal;
use FireflyIII\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A category suggestion produced by the AI categoriser for a transaction journal.
 *
 * @property int         $id
 * @property int         $user_id
 * @property null|int    $transaction_journal_id
 * @property null|string $suggested_category
 * @property float       $confidence   Value in [0, 1]
 * @property string      $status       pending | accepted | rejected
 */
class AISuggestion extends Model
{
    use SoftDeletes;

    protected $table    = 'extension_ai_suggestions';

    protected $fillable = [
        'user_id',
        'transaction_journal_id',
        'suggested_category',
        'confidence',
        'status',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactionJournal(): BelongsTo
    {
        return $this->belongsTo(TransactionJournal::class);
    }

    protected function casts(): array
    {
        return [
            'created_at'             => 'datetime',
            'updated_at'             => 'datetime',
            'deleted_at'             => 'datetime',
            'user_id'                => 'integer',
            'transaction_journal_id' => 'integer',
            'confidence'             => 'float',
        ];
    }
}
