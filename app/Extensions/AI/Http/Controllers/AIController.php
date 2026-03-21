<?php

declare(strict_types=1);

namespace FireflyIII\Extensions\AI\Http\Controllers;

use FireflyIII\Extensions\AI\Exceptions\AIServiceException;
use FireflyIII\Extensions\AI\Http\Requests\AISettingsRequest;
use FireflyIII\Extensions\AI\Http\Requests\NLParseRequest;
use FireflyIII\Extensions\AI\Repositories\AISettingsRepository;
use FireflyIII\Extensions\AI\Repositories\AISuggestionRepository;
use FireflyIII\Extensions\AI\Services\AIInsightService;
use FireflyIII\Extensions\AI\Services\NLTransactionParser;
use FireflyIII\Extensions\AI\Transformers\AIInsightTransformer;
use FireflyIII\Extensions\AI\Transformers\AISuggestionTransformer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

/**
 * Handles all AI Extension API endpoints under /v1/ext/ai.
 *
 * Routes:
 *   GET    /v1/ext/ai/settings           → getSettings
 *   POST   /v1/ext/ai/settings           → storeSettings
 *   GET    /v1/ext/ai/insights           → getInsights
 *   POST   /v1/ext/ai/parse-nl           → parseNL          (throttled: 10/min)
 *   GET    /v1/ext/ai/suggestions        → getSuggestions
 *   PUT    /v1/ext/ai/suggestions/{id}/accept   → acceptSuggestion
 *   PUT    /v1/ext/ai/suggestions/{id}/reject   → rejectSuggestion
 */
class AIController extends Controller
{
    public function __construct(
        private readonly AISettingsRepository $settingsRepository,
        private readonly AISuggestionRepository $suggestionRepository,
        private readonly AIInsightService $insightService,
        private readonly NLTransactionParser $parser,
        private readonly AISuggestionTransformer $suggestionTransformer,
        private readonly AIInsightTransformer $insightTransformer
    ) {}

    // -----------------------------------------------------------------------
    // GET /v1/ext/ai/settings
    // -----------------------------------------------------------------------

    public function getSettings(Request $request): JsonResponse
    {
        /** @var \FireflyIII\User $user */
        $user = $request->user();
        $this->settingsRepository->setUser($user);

        $settings = $this->settingsRepository->getForUser();

        if (null === $settings) {
            return response()->json(['data' => null], 200);
        }

        return response()->json([
            'data' => [
                'id'         => $settings->id,
                'type'       => 'ai_settings',
                'attributes' => [
                    'provider'   => $settings->provider->value,
                    'is_enabled' => $settings->is_enabled,
                    // api_key is intentionally omitted — never exposed via API
                    'created_at' => $settings->created_at?->toIso8601String(),
                    'updated_at' => $settings->updated_at?->toIso8601String(),
                ],
            ],
        ]);
    }

    // -----------------------------------------------------------------------
    // POST /v1/ext/ai/settings
    // -----------------------------------------------------------------------

    public function storeSettings(AISettingsRequest $request): JsonResponse
    {
        /** @var \FireflyIII\User $user */
        $user = $request->user();
        $this->settingsRepository->setUser($user);

        $settings = $this->settingsRepository->upsert([
            'provider'   => $request->validated()['provider'],
            'api_key'    => $request->validated()['api_key'],
            'is_enabled' => (bool) $request->validated()['is_enabled'],
        ]);

        return response()->json([
            'data' => [
                'id'         => $settings->id,
                'type'       => 'ai_settings',
                'attributes' => [
                    'provider'   => $settings->provider->value,
                    'is_enabled' => $settings->is_enabled,
                    'created_at' => $settings->created_at?->toIso8601String(),
                    'updated_at' => $settings->updated_at?->toIso8601String(),
                ],
            ],
        ], 201);
    }

    // -----------------------------------------------------------------------
    // GET /v1/ext/ai/insights
    // -----------------------------------------------------------------------

    public function getInsights(Request $request): JsonResponse
    {
        /** @var \FireflyIII\User $user */
        $user    = $request->user();
        $insights = $this->insightService->buildInsights($user);

        return response()->json(['data' => $this->insightTransformer->transform($insights)]);
    }

    // -----------------------------------------------------------------------
    // POST /v1/ext/ai/parse-nl  (throttled: 10 requests/min per user)
    // -----------------------------------------------------------------------

    public function parseNL(NLParseRequest $request): JsonResponse
    {
        /** @var \FireflyIII\User $user */
        $user  = $request->user();
        $this->settingsRepository->setUser($user);
        $this->parser->setHttpClient(new \GuzzleHttp\Client(['timeout' => 15.0]));

        try {
            $result = $this->parser->parse($request->validated()['input']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (AIServiceException $e) {
            Log::error('[AI] NL parse failed', ['user_id' => $user->id, 'message' => $e->getMessage()]);

            return response()->json(['message' => 'AI service error — please try again later'], 503);
        }

        if (null === $result) {
            return response()->json(['message' => 'AI not configured or disabled'], 422);
        }

        return response()->json(['data' => $result]);
    }

    // -----------------------------------------------------------------------
    // GET /v1/ext/ai/suggestions
    // -----------------------------------------------------------------------

    public function getSuggestions(Request $request): JsonResponse
    {
        /** @var \FireflyIII\User $user */
        $user        = $request->user();
        $suggestions = $this->suggestionRepository->getPendingForUser($user->id);

        $data = $suggestions->map(fn ($s) => $this->suggestionTransformer->transform($s))->values()->all();

        return response()->json(['data' => $data]);
    }

    // -----------------------------------------------------------------------
    // PUT /v1/ext/ai/suggestions/{id}/accept
    // -----------------------------------------------------------------------

    public function acceptSuggestion(Request $request, int $id): JsonResponse
    {
        $updated = $this->suggestionRepository->markAccepted($id);

        return response()->json(['data' => $this->suggestionTransformer->transform($updated)]);
    }

    // -----------------------------------------------------------------------
    // PUT /v1/ext/ai/suggestions/{id}/reject
    // -----------------------------------------------------------------------

    public function rejectSuggestion(Request $request, int $id): JsonResponse
    {
        $updated = $this->suggestionRepository->markRejected($id);

        return response()->json(['data' => $this->suggestionTransformer->transform($updated)]);
    }
}
