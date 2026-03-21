<?php

declare(strict_types=1);

namespace FireflyIII\Extensions\Notifications\Services;

use FireflyIII\Extensions\Notifications\Models\PushSubscription;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Service for building and dispatching VAPID-signed Web Push notifications.
 *
 * The actual encrypted HTTP request to a push service requires a library such
 * as minishlink/web-push. Until that dependency is added, the send() method
 * calls the HTTP facade so it can be cleanly faked in tests.
 */
class WebPushService
{
    public function __construct(
        private readonly string $subject,
        private readonly string $publicKey,
        private readonly string $privateKey,
    ) {}

    // ─── Payload Builder ─────────────────────────────────────────────────────

    /**
     * Serialise a push notification payload to a JSON string.
     *
     * @param array<string, mixed> $data Optional extra key/value data
     */
    public function buildPayload(string $title, string $body, array $data = []): string
    {
        $payload = [
            'title' => $title,
            'body'  => $body,
        ];

        if ([] !== $data) {
            $payload['data'] = $data;
        }

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // ─── Subscription Validation ─────────────────────────────────────────────

    /**
     * Return true if the subscription has all required non-empty fields.
     */
    public function validateSubscription(PushSubscription $subscription): bool
    {
        return !empty($subscription->endpoint)
            && !empty($subscription->p256dh_key)
            && !empty($subscription->auth_key);
    }

    // ─── Budget Alert Threshold ───────────────────────────────────────────────

    /**
     * Determine whether a budget alert should be sent.
     *
     * @param string $spent     Amount spent so far (decimal string)
     * @param string $limit     Budget limit (decimal string)
     * @param float  $threshold Fraction at which to trigger, e.g. 0.80 for 80%
     */
    public function shouldSendBudgetAlert(string $spent, string $limit, float $threshold): bool
    {
        // Cannot divide by zero – no alert when limit is 0
        if (0 === bccomp($limit, '0')) {
            return false;
        }

        $ratio = (float) bcdiv($spent, $limit, 10);

        return $ratio >= $threshold;
    }

    // ─── Dispatch ────────────────────────────────────────────────────────────

    /**
     * Send a push notification to the given subscription endpoint.
     * Uses the HTTP facade so tests can fake the request easily.
     *
     * In production this should be replaced with a proper VAPID-signing
     * implementation (e.g. minishlink/web-push).
     *
     * @param array<string, mixed> $data Optional payload data
     */
    public function send(PushSubscription $subscription, string $title, string $body, array $data = []): bool
    {
        if (!$this->validateSubscription($subscription)) {
            Log::warning('WebPushService: invalid subscription, skipping send.', [
                'subscription_id' => $subscription->id ?? null,
            ]);

            return false;
        }

        $payload = $this->buildPayload($title, $body, $data);

        try {
            $response = Http::withHeaders([
                'Content-Type'   => 'application/json',
                'Content-Length' => (string) strlen($payload),
            ])->withBody($payload, 'application/json')
                ->post($subscription->endpoint);

            if ($response->successful() || 201 === $response->status()) {
                return true;
            }

            Log::error('WebPushService: push delivery failed.', [
                'status'   => $response->status(),
                'endpoint' => $subscription->endpoint,
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::error('WebPushService: exception during send.', ['exception' => $e->getMessage()]);

            return false;
        }
    }
}
