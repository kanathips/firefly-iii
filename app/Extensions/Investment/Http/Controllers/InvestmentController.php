<?php

declare(strict_types=1);

namespace FireflyIII\Extensions\Investment\Http\Controllers;

use FireflyIII\Extensions\Investment\Repositories\InvestmentRepositoryInterface;
use FireflyIII\Extensions\Investment\Services\InvestmentService;
use FireflyIII\Extensions\Investment\Transformers\InvestmentPositionTransformer;
use FireflyIII\Extensions\Investment\Transformers\PortfolioSummaryTransformer;
use FireflyIII\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class InvestmentController extends Controller
{
    public function __construct(
        private readonly InvestmentRepositoryInterface $repository,
        private readonly InvestmentService $service,
        private readonly InvestmentPositionTransformer $positionTransformer,
        private readonly PortfolioSummaryTransformer $summaryTransformer,
    ) {}

    // ─── GET /v1/ext/investments ─────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user      = $request->user();
        $positions = $this->repository->findAllForUser($user);

        $data = $positions->map(fn ($p) => $this->positionTransformer->transform($p))->values()->all();

        return response()->json([
            'data' => $data,
            'meta' => ['count' => count($data)],
        ]);
    }

    // ─── GET /v1/ext/investments/summary ─────────────────────────────────────

    public function summary(Request $request): JsonResponse
    {
        /** @var User $user */
        $user    = $request->user();
        $summary = $this->service->getPortfolioSummary($user);

        return response()->json([
            'data' => $this->summaryTransformer->transform($summary),
        ]);
    }

    // ─── GET /v1/ext/investments/{id} ────────────────────────────────────────

    public function show(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user     = $request->user();
        $position = $this->repository->findById($id, $user);

        if (null === $position) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        return response()->json([
            'data' => $this->positionTransformer->transform($position),
        ]);
    }

    // ─── POST /v1/ext/investments ────────────────────────────────────────────

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'account_id' => ['required', 'integer', 'exists:accounts,id'],
            'symbol'     => ['required', 'string', 'max:20'],
            'quantity'   => ['required', 'numeric', 'min:0'],
            'avg_cost'   => ['required', 'numeric', 'min:0'],
            'notes'      => ['nullable', 'string'],
        ]);

        /** @var User $user */
        $user     = $request->user();
        $position = $this->repository->storePosition([
            'user_id'    => $user->id,
            'account_id' => (int) $validated['account_id'],
            'symbol'     => strtoupper(trim($validated['symbol'])),
            'quantity'   => number_format((float) $validated['quantity'], 2, '.', ''),
            'avg_cost'   => number_format((float) $validated['avg_cost'], 2, '.', ''),
            'notes'      => $validated['notes'] ?? null,
        ]);

        return response()->json([
            'data' => $this->positionTransformer->transform($position),
        ], 201);
    }

    // ─── PUT /v1/ext/investments/{id} ────────────────────────────────────────

    public function update(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user     = $request->user();
        $position = $this->repository->findById($id, $user);

        if (null === $position) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $validated = $request->validate([
            'symbol'   => ['sometimes', 'string', 'max:20'],
            'quantity' => ['sometimes', 'numeric', 'min:0'],
            'avg_cost' => ['sometimes', 'numeric', 'min:0'],
            'notes'    => ['nullable', 'string'],
        ]);

        $data = [];
        if (isset($validated['symbol'])) {
            $data['symbol'] = strtoupper(trim($validated['symbol']));
        }
        if (isset($validated['quantity'])) {
            $data['quantity'] = number_format((float) $validated['quantity'], 2, '.', '');
        }
        if (isset($validated['avg_cost'])) {
            $data['avg_cost'] = number_format((float) $validated['avg_cost'], 2, '.', '');
        }
        if (array_key_exists('notes', $validated)) {
            $data['notes'] = $validated['notes'];
        }

        $updated = $this->repository->updatePosition($position, $data);

        return response()->json([
            'data' => $this->positionTransformer->transform($updated),
        ]);
    }

    // ─── DELETE /v1/ext/investments/{id} ─────────────────────────────────────

    public function destroy(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user     = $request->user();
        $position = $this->repository->findById($id, $user);

        if (null === $position) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $this->repository->destroyPosition($position);

        return response()->json(null, 204);
    }

    // ─── POST /v1/ext/investments/{id}/snapshots ─────────────────────────────

    public function storeSnapshot(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user     = $request->user();
        $position = $this->repository->findById($id, $user);

        if (null === $position) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $validated = $request->validate([
            'current_price' => ['required', 'numeric', 'min:0'],
            'snapshot_date' => ['required', 'date'],
        ]);

        $snapshot = $this->repository->storeSnapshot([
            'position_id'   => $position->id,
            'current_price' => number_format((float) $validated['current_price'], 2, '.', ''),
            'snapshot_date' => $validated['snapshot_date'],
        ]);

        return response()->json([
            'data' => [
                'id'         => $snapshot->id,
                'type'       => 'investment_snapshot',
                'attributes' => [
                    'position_id'   => $snapshot->position_id,
                    'current_price' => (string) $snapshot->current_price,
                    'snapshot_date' => $snapshot->snapshot_date?->format('Y-m-d'),
                    'created_at'    => $snapshot->created_at?->toISOString(),
                ],
            ],
        ], 201);
    }
}
