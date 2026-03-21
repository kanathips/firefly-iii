<?php

declare(strict_types=1);

namespace FireflyIII\Extensions\Import\Models;

/**
 * Value object representing a parsed-but-not-yet-imported batch of transactions.
 *
 * Previews are stored in the cache (not the database) and expire after a configurable TTL.
 */
final class TransactionPreview
{
    /**
     * @param  int                                                                                               $userId
     * @param  list<array{date: ?string, payee: ?string, amount: float, is_debit: bool, confidence: float, skip?: bool}>  $transactions
     * @param  list<array{date: ?string, payee: ?string, amount: float}>                                        $duplicates
     */
    public function __construct(
        public readonly int    $userId,
        public readonly array  $transactions,
        public readonly array  $duplicates = [],
    ) {}

    /**
     * Create from a raw cache-stored array.
     *
     * @param  array{user_id: int, transactions: list<array>, duplicates?: list<array>}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            userId:       (int) $data['user_id'],
            transactions: $data['transactions'] ?? [],
            duplicates:   $data['duplicates'] ?? [],
        );
    }

    /**
     * Serialise to array for cache storage.
     *
     * @return array{user_id: int, transactions: list<array>, duplicates: list<array>}
     */
    public function toArray(): array
    {
        return [
            'user_id'      => $this->userId,
            'transactions' => $this->transactions,
            'duplicates'   => $this->duplicates,
        ];
    }

    public function transactionCount(): int
    {
        return count($this->transactions);
    }

    public function duplicateCount(): int
    {
        return count($this->duplicates);
    }
}
