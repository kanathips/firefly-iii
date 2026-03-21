<?php

declare(strict_types=1);

namespace FireflyIII\Extensions\AI\Services;

use FireflyIII\Extensions\AI\Enums\AIProvider;
use FireflyIII\Extensions\AI\Exceptions\AIQuotaExceededException;
use FireflyIII\Extensions\AI\Exceptions\AIServiceException;
use FireflyIII\Extensions\AI\Models\AISettings;
use FireflyIII\Extensions\AI\Repositories\AISettingsRepository;
use FireflyIII\Models\TransactionJournal;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

/**
 * Calls the configured AI provider to suggest a spending category for a
 * transaction journal.
 *
 * Security notes:
 * - Journal description is sanitized before inclusion in any prompt to prevent
 *   prompt-injection attacks.
 * - Descriptions are truncated to a safe maximum length before use.
 * - API keys are retrieved via the repository (decrypted in-memory, not logged).
 */
class AICategorizer
{
    private const MAX_DESCRIPTION_LENGTH = 200;
    private const PROMPT_MAX_LENGTH      = 600;

    private ?Client $httpClient = null;

    public function __construct(private readonly AISettingsRepository $settingsRepository) {}

    /**
     * Inject a custom HTTP client (used in tests to mock responses).
     */
    public function setHttpClient(Client $client): void
    {
        $this->httpClient = $client;
    }

    /**
     * Attempt to categorise the given journal using the AI provider.
     *
     * Returns an associative array with keys 'category' (string) and
     * 'confidence' (float in [0,1]) on success, or null when AI is disabled
     * or no settings exist.
     *
     * @return null|array{category: string, confidence: float}
     *
     * @throws AIQuotaExceededException  when the provider returns HTTP 429
     * @throws AIServiceException        on any other API error
     */
    public function categorize(TransactionJournal $journal): ?array
    {
        $settings = $this->settingsRepository->getForUser();

        if (null === $settings || false === $settings->is_enabled) {
            return null;
        }

        $apiKey = $this->settingsRepository->decryptApiKey($settings);

        if (null === $apiKey) {
            Log::warning('[AI] Cannot categorize: API key decryption failed', ['journal_id' => $journal->id]);

            return null;
        }

        $prompt = $this->buildCategorizationPrompt($journal);

        return $this->callProvider($settings->provider, $apiKey, $prompt);
    }

    /**
     * Build a sanitized categorization prompt for the given journal.
     * The prompt is capped at PROMPT_MAX_LENGTH characters.
     */
    public function buildCategorizationPrompt(TransactionJournal $journal): string
    {
        $raw         = (string) ($journal->description ?? '');
        $sanitized   = $this->sanitizeDescription($raw);
        $truncated   = mb_substr($sanitized, 0, self::MAX_DESCRIPTION_LENGTH);

        $prompt      = sprintf(
            'Classify the following transaction into a spending category. '
            . 'Return JSON with keys "category" (string) and "confidence" (float 0-1). '
            . 'Transaction: "%s"',
            $truncated
        );

        return mb_substr($prompt, 0, self::PROMPT_MAX_LENGTH);
    }

    /**
     * Remove content that could be used for prompt injection.
     *
     * Strips newlines, known injection keywords, and trims whitespace.
     */
    private function sanitizeDescription(string $description): string
    {
        // Remove newlines and carriage returns which could break structured prompts
        $sanitized = str_replace(["\n", "\r", "\t"], ' ', $description);

        // Block known prompt-injection patterns (case-insensitive)
        $injectionPatterns = [
            '/ignore\s+(previous|all)\s+instructions?/i',
            '/reveal\s+(your\s+)?(api\s*key|prompt|system|config)/i',
            '/SYSTEM\s*:/i',
            '/\[INST\]/i',
            '/print\s+your\s+system\s+prompt/i',
        ];

        foreach ($injectionPatterns as $pattern) {
            $sanitized = preg_replace($pattern, '', $sanitized) ?? $sanitized;
        }

        return trim($sanitized);
    }

    /**
     * @return array{category: string, confidence: float}
     *
     * @throws AIQuotaExceededException
     * @throws AIServiceException
     */
    private function callProvider(AIProvider $provider, string $apiKey, string $prompt): array
    {
        $client = $this->httpClient ?? new Client(['timeout' => 15.0]);

        $endpoint = match ($provider) {
            AIProvider::OPENAI    => 'https://api.openai.com/v1/chat/completions',
            AIProvider::ANTHROPIC => 'https://api.anthropic.com/v1/messages',
        };

        $payload = $this->buildPayload($provider, $prompt);

        try {
            $response   = $client->post($endpoint, [
                'headers' => $this->buildHeaders($provider, $apiKey),
                'json'    => $payload,
            ]);

            $statusCode = $response->getStatusCode();

            if (429 === $statusCode) {
                throw new AIQuotaExceededException('AI provider rate limit exceeded (HTTP 429)');
            }

            if ($statusCode >= 400) {
                throw new AIServiceException(sprintf('AI provider returned HTTP %d', $statusCode));
            }

            return $this->parseResponse($provider, $response);
        } catch (AIQuotaExceededException | AIServiceException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new AIServiceException('AI API call failed: ' . $e->getMessage(), 0, $e);
        }
    }

    private function buildHeaders(AIProvider $provider, string $apiKey): array
    {
        return match ($provider) {
            AIProvider::OPENAI    => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type'  => 'application/json',
            ],
            AIProvider::ANTHROPIC => [
                'x-api-key'         => $apiKey,
                'anthropic-version' => '2023-06-01',
                'Content-Type'      => 'application/json',
            ],
        };
    }

    private function buildPayload(AIProvider $provider, string $prompt): array
    {
        return match ($provider) {
            AIProvider::OPENAI    => [
                'model'    => 'gpt-4o-mini',
                'messages' => [['role' => 'user', 'content' => $prompt]],
            ],
            AIProvider::ANTHROPIC => [
                'model'      => 'claude-haiku-4-5',
                'max_tokens' => 256,
                'messages'   => [['role' => 'user', 'content' => $prompt]],
            ],
        };
    }

    /**
     * @return array{category: string, confidence: float}
     */
    private function parseResponse(AIProvider $provider, \Psr\Http\Message\ResponseInterface $response): array
    {
        $body    = $response->getBody()->getContents();
        $decoded = json_decode($body, true);

        $content = match ($provider) {
            AIProvider::OPENAI    => $decoded['choices'][0]['message']['content'] ?? '{}',
            AIProvider::ANTHROPIC => $decoded['content'][0]['text'] ?? '{}',
        };

        $parsed     = json_decode($content, true) ?? [];
        $category   = (string) ($parsed['category'] ?? 'Uncategorized');
        $confidence = (float) ($parsed['confidence'] ?? 0.0);

        return [
            'category'   => $category,
            'confidence' => max(0.0, min(1.0, $confidence)),
        ];
    }
}
