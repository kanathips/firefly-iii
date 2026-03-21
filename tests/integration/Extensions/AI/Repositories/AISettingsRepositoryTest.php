<?php

declare(strict_types=1);

namespace Tests\integration\Extensions\AI\Repositories;

use FireflyIII\Extensions\AI\Enums\AIProvider;
use FireflyIII\Extensions\AI\Models\AISettings;
use FireflyIII\Extensions\AI\Repositories\AISettingsRepository;
use Illuminate\Support\Facades\Crypt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\integration\TestCase;

/**
 * @internal
 */
#[CoversClass(AISettingsRepository::class)]
#[Group('integration')]
#[Group('ai')]
final class AISettingsRepositoryTest extends TestCase
{
    private AISettingsRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new AISettingsRepository();
    }

    #[Test]
    public function testReturnsNullWhenNoSettingsExist(): void
    {
        $user = $this->createAuthenticatedUser();
        $this->repo->setUser($user);

        $settings = $this->repo->getForUser();

        $this->assertNull($settings);
    }

    #[Test]
    public function testUpsertCreatesNewSettingsWithEncryptedKey(): void
    {
        $user = $this->createAuthenticatedUser();
        $this->repo->setUser($user);

        $settings = $this->repo->upsert([
            'provider'   => 'openai',
            'api_key'    => 'sk-plaintext-key-12345',
            'is_enabled' => true,
        ]);

        $this->assertInstanceOf(AISettings::class, $settings);
        $this->assertSame(AIProvider::OPENAI, $settings->provider);
        $this->assertTrue($settings->is_enabled);

        // Stored api_key must NOT be the plaintext key
        $this->assertNotSame('sk-plaintext-key-12345', $settings->api_key);

        // Decrypting the stored value must return the original plaintext
        $this->assertSame('sk-plaintext-key-12345', Crypt::decryptString($settings->api_key));
    }

    #[Test]
    public function testUpsertUpdatesExistingSettingsForSameUser(): void
    {
        $user = $this->createAuthenticatedUser();
        $this->repo->setUser($user);

        $this->repo->upsert([
            'provider'   => 'openai',
            'api_key'    => 'sk-original-key-12345',
            'is_enabled' => true,
        ]);

        // Upsert again with different provider and disabled flag
        $updated = $this->repo->upsert([
            'provider'   => 'anthropic',
            'api_key'    => 'sk-new-key-anthropic-12345',
            'is_enabled' => false,
        ]);

        $this->assertSame(AIProvider::ANTHROPIC, $updated->provider);
        $this->assertFalse($updated->is_enabled);

        // Only one record should exist per user
        $count = AISettings::where('user_id', $user->id)->count();
        $this->assertSame(1, $count);
    }

    #[Test]
    public function testGetForUserReturnsSettingsAfterUpsert(): void
    {
        $user = $this->createAuthenticatedUser();
        $this->repo->setUser($user);

        $this->repo->upsert([
            'provider'   => 'openai',
            'api_key'    => 'sk-retrieve-key-12345',
            'is_enabled' => true,
        ]);

        $retrieved = $this->repo->getForUser();

        $this->assertNotNull($retrieved);
        $this->assertSame(AIProvider::OPENAI, $retrieved->provider);
    }

    #[Test]
    public function testDecryptApiKeyReturnsPlaintextKey(): void
    {
        $user = $this->createAuthenticatedUser();
        $this->repo->setUser($user);

        $settings = $this->repo->upsert([
            'provider'   => 'openai',
            'api_key'    => 'sk-decrypt-test-12345',
            'is_enabled' => true,
        ]);

        $decrypted = $this->repo->decryptApiKey($settings);

        $this->assertSame('sk-decrypt-test-12345', $decrypted);
    }

    #[Test]
    public function testDecryptApiKeyReturnsNullForCorruptedCiphertext(): void
    {
        $user = $this->createAuthenticatedUser();
        $this->repo->setUser($user);

        $settings          = new AISettings();
        $settings->user_id = $user->id;
        $settings->api_key = 'not-valid-ciphertext';  // Corrupted

        $decrypted = $this->repo->decryptApiKey($settings);

        $this->assertNull($decrypted);
    }

    #[Test]
    public function testSettingsAreIsolatedBetweenUsers(): void
    {
        $userA = $this->createAuthenticatedUser();
        $this->repo->setUser($userA);

        $this->repo->upsert([
            'provider'   => 'openai',
            'api_key'    => 'sk-user-a-key-12345',
            'is_enabled' => true,
        ]);

        // Create a second user
        $userB = \FireflyIII\User::create([
            'email'         => 'userb-settings@test.com',
            'password'      => 'password',
            'user_group_id' => $userA->user_group_id,
        ]);

        $this->repo->setUser($userB);
        $settingsForB = $this->repo->getForUser();

        $this->assertNull($settingsForB);
    }
}
