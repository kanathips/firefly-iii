<?php

declare(strict_types=1);

namespace FireflyIII\Extensions\AI\Repositories;

use FireflyIII\Extensions\AI\Models\AISuggestion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Handles persistence and querying of AI category suggestions.
 */
class AISuggestionRepository
{
    /**
     * Create and persist a new suggestion.
     *
     * @param array{
     *   user_id: int,
     *   transaction_journal_id: int|null,
     *   suggested_category: string,
     *   confidence: float,
     *   status: string
     * } $data
     */
    public function create(array $data): AISuggestion
    {
        /** @var AISuggestion $suggestion */
        $suggestion = AISuggestion::create($data);

        Log::debug(sprintf(
            '[AI] Suggestion created #%d category="%s" confidence=%.2f',
            $suggestion->id,
            $suggestion->suggested_category,
            $suggestion->confidence
        ));

        return $suggestion;
    }

    /**
     * Return all pending suggestions for the given user, newest first.
     */
    public function getPendingForUser(int $userId): Collection
    {
        return AISuggestion::where('user_id', $userId)
            ->where('status', 'pending')
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Count pending suggestions for the given user.
     */
    public function countPendingForUser(int $userId): int
    {
        return AISuggestion::where('user_id', $userId)
            ->where('status', 'pending')
            ->count();
    }

    /**
     * Mark a suggestion as accepted. Returns the updated model.
     */
    public function markAccepted(int $suggestionId): AISuggestion
    {
        return $this->updateStatus($suggestionId, 'accepted');
    }

    /**
     * Mark a suggestion as rejected. Returns the updated model.
     */
    public function markRejected(int $suggestionId): AISuggestion
    {
        return $this->updateStatus($suggestionId, 'rejected');
    }

    /**
     * Soft-delete a suggestion.
     */
    public function delete(int $suggestionId): void
    {
        AISuggestion::where('id', $suggestionId)->delete();
    }

    private function updateStatus(int $suggestionId, string $status): AISuggestion
    {
        /** @var AISuggestion $suggestion */
        $suggestion         = AISuggestion::findOrFail($suggestionId);
        $suggestion->status = $status;
        $suggestion->save();

        return $suggestion;
    }
}
