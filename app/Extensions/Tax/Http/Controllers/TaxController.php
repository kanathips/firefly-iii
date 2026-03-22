<?php

declare(strict_types=1);

namespace FireflyIII\Extensions\Tax\Http\Controllers;

use Carbon\Carbon;
use FireflyIII\Extensions\Tax\Repositories\TaxRepositoryInterface;
use FireflyIII\Extensions\Tax\Services\TaxCalculationService;
use FireflyIII\Extensions\Tax\Transformers\TaxSummaryTransformer;
use FireflyIII\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use function Safe\fclose;
use function Safe\fopen;
use function Safe\fputcsv;
use function Safe\rewind;
use function Safe\stream_get_contents;

/**
 * REST controller for tax profiles and related operations.
 *
 * Routes (prefix: /api/v1/ext/tax):
 *   POST   /profiles                        – create a new profile
 *   GET    /profiles                        – list profiles for the current user
 *   GET    /profiles/{profile}/summary      – tax deductible summary
 *   GET    /profiles/{profile}/export       – CSV export
 *   POST   /profiles/{profile}/tags         – link a tag to a profile
 */
final class TaxController extends Controller
{
    public function __construct(
        private readonly TaxRepositoryInterface $repository,
        private readonly TaxCalculationService  $calculationService,
        private readonly TaxSummaryTransformer  $transformer,
    ) {
        // Guarantee setUser() is called after the auth:api middleware has run,
        // regardless of when the service container resolved the repository.
        $this->middleware(function (\Illuminate\Http\Request $request, \Closure $next) {
            $this->repository->setUser(auth()->user());

            return $next($request);
        });
    }

    // -----------------------------------------------------------------------
    // POST /api/v1/ext/tax/profiles
    // -----------------------------------------------------------------------

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name'     => 'required|string|max:255',
            'tax_year' => 'required|integer|min:1900|max:2200',
            'tax_rate' => 'sometimes|numeric|min:0|max:100',
            'notes'    => 'sometimes|nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $profile   = $this->repository->createProfile($validator->validated());

        return response()->json(['data' => $this->transformer->transform($profile)], 201);
    }

    // -----------------------------------------------------------------------
    // GET /api/v1/ext/tax/profiles
    // -----------------------------------------------------------------------

    public function index(): JsonResponse
    {
        $profiles = $this->repository->getProfiles();

        $data     = $profiles->map(fn ($p) => $this->transformer->transform($p))->values()->toArray();

        return response()->json(['data' => $data]);
    }

    // -----------------------------------------------------------------------
    // GET /api/v1/ext/tax/profiles/{profile}/summary
    // -----------------------------------------------------------------------

    public function summary(Request $request, int $profileId): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'start'  => 'required|date_format:Y-m-d',
            'end'    => 'required|date_format:Y-m-d',
            'period' => 'sometimes|string|in:month,year',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $profile   = $this->repository->findProfile($profileId);

        if (null === $profile) {
            return response()->json(['message' => 'Profile not found.'], 404);
        }

        $params    = $validator->validated();
        $start     = Carbon::parse($params['start']);
        $end       = Carbon::parse($params['end']);
        $period    = $params['period'] ?? 'month';

        $summary   = $this->calculationService->buildSummary($profile, $start, $end, $period);

        return response()->json($this->transformer->transformSummary($summary));
    }

    // -----------------------------------------------------------------------
    // GET /api/v1/ext/tax/profiles/{profile}/export
    // -----------------------------------------------------------------------

    public function export(Request $request, int $profileId): JsonResponse|Response
    {
        $validator = Validator::make($request->query(), [
            'start' => 'required|date_format:Y-m-d',
            'end'   => 'required|date_format:Y-m-d',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $profile   = $this->repository->findProfile($profileId);

        if (null === $profile) {
            return response()->json(['message' => 'Profile not found.'], 404);
        }

        $params    = $validator->validated();
        $start     = Carbon::parse($params['start']);
        $end       = Carbon::parse($params['end']);
        $journals  = $this->repository->getDeductibleJournals($profile, $start, $end);

        $csv       = $this->buildCsv($journals);
        $safeName  = Str::slug($profile->name, '_');
        $filename  = sprintf('tax-export-%s-%d.csv', $safeName, $profile->tax_year);

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
        ]);
    }

    // -----------------------------------------------------------------------
    // POST /api/v1/ext/tax/profiles/{profile}/tags
    // -----------------------------------------------------------------------

    public function linkTag(Request $request, int $profileId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'tag_id' => 'required|integer|exists:tags,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $profile   = $this->repository->findProfile($profileId);

        if (null === $profile) {
            return response()->json(['message' => 'Profile not found.'], 404);
        }

        /** @var Tag $tag */
        $tag       = auth()->user()->tags()->findOrFail($request->integer('tag_id'));
        $link      = $this->repository->linkTag($profile, $tag);

        return response()->json([
            'data' => [
                'tax_profile_id' => $link->tax_profile_id,
                'tag_id'         => $link->tag_id,
            ],
        ]);
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Build a CSV string from journal rows.
     *
     * @param array<int, array{amount: string, category: null|string, date: string, description: string}> $journals
     */
    private function buildCsv(array $journals): string
    {
        $handle = fopen('php://memory', 'r+');
        fputcsv($handle, ['date', 'description', 'category', 'amount']);

        foreach ($journals as $journal) {
            fputcsv($handle, [
                $journal['date'],
                $journal['description'],
                $journal['category'] ?? '',
                $journal['amount'],
            ]);
        }

        rewind($handle);
        $csv    = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
