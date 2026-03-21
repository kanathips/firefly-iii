<?php

declare(strict_types=1);

namespace FireflyIII\Extensions\Investment\Repositories;

use FireflyIII\Extensions\Investment\Models\InvestmentPosition;
use FireflyIII\Extensions\Investment\Models\InvestmentSnapshot;
use FireflyIII\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class InvestmentRepository implements InvestmentRepositoryInterface
{
    private User $user;

    public function setUser(null|Authenticatable|User $user): void
    {
        /** @var User $user */
        $this->user = $user;
    }

    public function findAllForUser(User $user): Collection
    {
        return InvestmentPosition::where('user_id', $user->id)
            ->with('latestSnapshot')
            ->orderBy('symbol')
            ->get();
    }

    public function findById(int $id, User $user): ?InvestmentPosition
    {
        return InvestmentPosition::where('id', $id)
            ->where('user_id', $user->id)
            ->with('latestSnapshot')
            ->first();
    }

    public function storePosition(array $data): InvestmentPosition
    {
        return InvestmentPosition::create($data);
    }

    public function updatePosition(InvestmentPosition $position, array $data): InvestmentPosition
    {
        $position->fill($data);
        $position->save();

        return $position->fresh();
    }

    public function destroyPosition(InvestmentPosition $position): void
    {
        Log::debug(sprintf('Deleting InvestmentPosition #%d for user #%d', $position->id, $position->user_id));
        $position->snapshots()->delete();
        $position->delete();
    }

    public function storeSnapshot(array $data): InvestmentSnapshot
    {
        return InvestmentSnapshot::create($data);
    }

    public function findSnapshotsByPosition(InvestmentPosition $position): Collection
    {
        return InvestmentSnapshot::where('position_id', $position->id)
            ->orderByDesc('snapshot_date')
            ->get();
    }
}
