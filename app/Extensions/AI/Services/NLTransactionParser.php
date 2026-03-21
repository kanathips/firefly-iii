<?php

declare(strict_types=1);

namespace FireflyIII\Extensions\AI\Services;

use FireflyIII\Extensions\AI\Enums\AIProvider;
use FireflyIII\Extensions\AI\Exceptions\AIServiceException;
use FireflyIII\Extensions\AI\Repositories\AISettingsRepository;
use GuzzleHttp\Client;
use InvalidArgumentException;

/**
 * Parses a natural-language transaction description into structured fields
 * using the configured AI provider.
 *
 * Security notes:
 * - Input is limited to 500 characters maximum.
 * - Prompt-injection patterns are stripped before sending to the AI.
 * - Payee / merchant names are sanitized to remove SQL injection attempts.
 * - API keys are retrieved encrypted and decrypted in-memory only.
 */
class NLTransactionParser
{
    private const MAX_INPUT_LENGTH = 500;

    /** Patterns that indicate prompt-injection attempts. */
    private const INJECTION_PATTERNS = [
        '/\n.*ignore\s+(all\s+)?.*instructions?/i',
        '/ignore\s+(all\s+)?.*instructions?/i',
        '/reveal\s+(your\s+)?(api\s*key|prompt|system|config)/i',
        '/SYSTEM\s*:/i',
        '/\[INST\]/i',
        '/print\s+your\s+system\s+prompt/i',
        '/developer\s+mode/i',
    ];

    private ?Client $httpClient = null;

    public function __construct(private readonly AISettingsRepository $settingsRepository) {}

    /**
     * Inject a custom HTTP client (used in tests).
     */
    public function setHttpClient(Client $client): void
    {
        $this->httpClient = $client;
    }

    /**
     * Parse a natural-language input string into a structured transaction.
     *
     * Returns null when AI is not configured for the current user.
     *
     * @return null|array{amount: float, date: string, payee: string, category: string, notes: string}
     *
     * @throws InvalidArgumentException  when input exceeds limits
     * @throws AIServiceException        on API failure
     */
    public function parse(string $input): ?array
    {
        $this->validateInput($input);

        $settings = $this->settingsRepository->getForUser();

        if (null === $settings || false === $settings->is_enabled) {
            return null;
        }

        $apiKey = $this->settingsRepository->decryptApiKey($settings);

        if (null === $apiKey) {
            return null;
        }

        $sanitized = $this->sanitizeInput($input);
        $prompt    = $this->buildPrompt($sanitized);

        return $this->callProvider($settings->provider, $apiKey, $prompt);
    }

    /**
     * Sanitize a raw input string by removing injection patterns.
     *
     * Public so that tests can verify sanitization in isolation.
     */
    public function sanitizeInput(string $input): string
    {
        // Strip newline-based injection attempts first
        $sanitized = preg_replace('/\r?\n.*/', '', $input) ?? $input;

        foreach (self::INJECTION_PATTERNS as $pattern) {
            $sanitized = preg_replace($pattern, '', $sanitized) ?? $sanitized;
        }

        // Truncate to safe length
        return mb_substr(trim($sanitized), 0, self::MAX_INPUT_LENGTH);
    }

    /**
     * Sanitize a payee/merchant name to remove SQL injection and command
     * injection sequences.
     *
     * Public so that tests can verify sanitization in isolation.
     */
    public function sanitizePayeeName(string $payee): string
    {
        // Remove SQL injection sequences
        $sanitized = preg_replace('/;\s*-{2,}.*$/m', '', $payee) ?? $payee;
        $sanitized = preg_replace('/\b(DROP|INSERT|DELETE|UPDATE|EXEC|UNION)\b.*$/i', '', $sanitized) ?? $sanitized;

        return trim($sanitized);
    }

    private function validateInput(string $input): void
    {
        if ('' === trim($input)) {
            throw new InvalidArgumentException('Input cannot be empty');
        }

        if (mb_strlen($input) > self::MAX_INPUT_LENGTH) {
            throw new InvalidArgumentException(
                sprintf('Input exceeds maximum length of %d characters', self::MAX_INPUT_LENGTH)
            );
        }
    }

    private function buildPrompt(string $sanitizedInput): string
    {
        return sprintf(
            'Parse the following natural language transaction into JSON with keys: '
            . '"amount" (float), "date" (YYYY-MM-DD), "payee" (string), "category" (string), "notes" (string). '
            . 'Use today\'s date if none is mentioned. '
            . 'Transaction: "%s"',
            $sanitizedInput
        );
    }

    /**
     * @return array{amount: float, date: string, payee: string, category: string, notes: string}
     *
     * @throws AIServiceException
     */
    private function callProvider(AIProvider $provider, string $apiKey, string $prompt): array
    {
        $client   = $this->httpClient ?? new Client(['timeout' => 15.0]);
        $endpoint = match ($provider) {
            AIProvider::OPENAI    => 'https://api.openai.com/v1/chat/completions',
            AIProvider::ANTHROPIC => 'https://api.anthropic.com/v1/messages',
        };

        $headers = match ($provider) {
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

        $payload = match ($provider) {
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

        try {
            $response = $client->post($endpoint, [
                'headers' => $headers,
                'json'    => $payload,
            ]);

            return $this->parseApiResponse($provider, $response);
        } catch (AIServiceException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new AIServiceException('NL parse API call failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @return array{amount: float, date: string, payee: string, category: string, notes: string}
     */
    private function parseApiResponse(AIProvider $provider, \Psr\Http\Message\ResponseInterface $response): array
    {
        $body    = $response->getBody()->getContents();
        $decoded = json_decode($body, true);

        $content = match ($provider) {
            AIProvider::OPENAI    => $decoded['choices'][0]['message']['content'] ?? '{}',
            AIProvider::ANTHROPIC => $decoded['content'][0]['text'] ?? '{}',
        };

        $parsed  = json_decode($content, true) ?? [];

        $payee   = $this->sanitizePayeeName((string) ($parsed['payee'] ?? ''));

        return [
            'amount'   => (float) ($parsed['amount'] ?? 0.0),
            'date'     => (string) ($parsed['date'] ?? date('Y-m-d')),
            'payee'    => $payee,
            'category' => (string) ($parsed['category'] ?? ''),
            'notes'    => (string) ($parsed['notes'] ?? ''),
        ];
    }
}
