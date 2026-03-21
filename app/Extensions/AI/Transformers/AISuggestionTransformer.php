<?php

declare(strict_types=1);

namespace FireflyIII\Extensions\AI\Transformers;

use FireflyIII\Extensions\AI\Models\AISuggestion;

/**
 * Transforms an AISuggestion Eloquent model into the JSON:API-compatible
 * envelope used by the AIController.
 */
class AISuggestionTransformer
{
    /**
     * @return array{
     *   id: int,
     *   type: string,
     *   attributes: array{
     *     transaction_journal_id: int|null,
     *     suggested_category: string|null,
     *     confidence: float,
     *     status: string,
     *     created_at: string,
     *     updated_at: string
     *   }
     * }
     */
    public function transform(AISuggestion $suggestion): array
    {
        return [
            'id'         => $suggestion->id,
            'type'       => 'ai_suggestion',
            'attributes' => [
                'transaction_journal_id' => $suggestion->transaction_journal_id,
                'suggested_category'     => $suggestion->suggested_category,
                'confidence'             => (float) $suggestion->confidence,
                'status'                 => $suggestion->status,
                'created_at'             => $suggestion->created_at?->toIso8601String() ?? '',
                'updated_at'             => $suggestion->updated_at?->toIso8601String() ?? '',
            ],
        ];
    }
}
