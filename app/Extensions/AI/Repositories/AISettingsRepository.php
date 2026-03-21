<?php

declare(strict_types=1);

namespace FireflyIII\Extensions\AI\Repositories;

use FireflyIII\Extensions\AI\Models\AISettings;
use FireflyIII\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Handles persistence of AI provider settings.
 *
 * API keys are encrypted before storage and decrypted on retrieval.  The
 * plaintext key is never written to logs or model attributes directly.
 */
class AISettingsRepository
{
    private User $user;

    public function setUser(null|Authenticatable|User $user): void
    {
        /** @var User $user */
        $this->user = $user;
    }

    /**
     * Retrieve the current user's AI settings.  Returns null when no record
     * exists or when no user has been set.
     * The api_key attribute on the returned model holds the *encrypted*
     * ciphertext; call decryptApiKey() to obtain the plaintext.
     */
    public function getForUser(): ?AISettings
    {
        if (!isset($this->user)) {
            return null;
        }

        return AISettings::where('user_id', $this->user->id)->first();
    }

    /**
     * Create or update the AI settings for the current user.
     *
     * @param array{provider: string, api_key: string, is_enabled: bool} $data
     *   api_key must be the *plaintext* key; this method encrypts it before storage.
     */
    public function upsert(array $data): AISettings
    {
        $encryptedKey = Crypt::encryptString($data['api_key']);

        /** @var AISettings $settings */
        $settings = AISettings::updateOrCreate(
            ['user_id' => $this->user->id],
            [
                'provider'   => $data['provider'],
                'api_key'    => $encryptedKey,
                'is_enabled' => $data['is_enabled'],
            ]
        );

        Log::debug(sprintf('[AI] Settings upserted for user #%d, provider=%s', $this->user->id, $data['provider']));

        return $settings;
    }

    /**
     * Decrypt and return the plaintext API key for the given settings record.
     * Logs a warning and returns null if decryption fails (e.g. key rotation).
     */
    public function decryptApiKey(AISettings $settings): ?string
    {
        try {
            return Crypt::decryptString($settings->api_key);
        } catch (\Exception $e) {
            Log::warning('[AI] Failed to decrypt API key', ['user_id' => $settings->user_id]);

            return null;
        }
    }
}
