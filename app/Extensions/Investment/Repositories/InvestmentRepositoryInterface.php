<?php

declare(strict_types=1);

namespace FireflyIII\Extensions\Investment\Repositories;

use FireflyIII\Extensions\Investment\Models\InvestmentPosition;
use FireflyIII\Extensions\Investment\Models\InvestmentSnapshot;
use FireflyIII\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;

interface InvestmentRepositoryInterface
{
    public function setUser(null|Authenticatable|User $user): void;

    /**
     * Return all InvestmentPositions belonging to $user, with latestSnapshot loaded.
     */
    public function findAllForUser(User $user): Collection;

    /**
     * Return a single InvestmentPosition by ID that belongs to $user.
     * Returns null if not found or belongs to another user.
     */
    public function findById(int $id, User $user): ?InvestmentPosition;

    /**
     * Persist a new InvestmentPosition.
     *
     * @param array{user_id: int, account_id: int, symbol: string, quantity: string, avg_cost: string, notes?: string} $data
     */
    public function storePosition(array $data): InvestmentPosition;

    /**
     * Update an existing InvestmentPosition with the given fields.
     *
     * @param array<string, mixed> $data
     */
    public function updatePosition(InvestmentPosition $position, array $data): InvestmentPosition;

    /**
     * Permanently delete an InvestmentPosition (and cascade its snapshots).
     */
    public function destroyPosition(InvestmentPosition $position): void;

    /**
     * Persist a new InvestmentSnapshot.
     *
     * @param array{position_id: int, current_price: string, snapshot_date: string} $data
     */
    public function storeSnapshot(array $data): InvestmentSnapshot;

    /**
     * Return all snapshots for a given position, most-recent-first.
     */
    public function findSnapshotsByPosition(InvestmentPosition $position): Collection;
}
