<?php

declare(strict_types=1);

namespace FireflyIII\Extensions\GoogleSheets\Services;

use RuntimeException;

/**
 * Handles Google Sheets OAuth2 flow, token encryption/decryption, and Sheets API calls.
 *
 * When the google/apiclient package is present (production), it delegates to the real
 * Google_Client.  In environments without the library the class can still be instantiated
 * and the non-HTTP methods (encrypt/decrypt, validateFieldMap, etc.) work fully.
 */
class GoogleSheetsConnector
{
    private const REQUIRED_FIELDS = ['date', 'amount', 'payee'];

    // Column letter → zero-based index map (A=0, B=1, …, Z=25)
    private const MAX_COLUMN = 'Z';

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $redirectUri,
    ) {}

    // -------------------------------------------------------------------------
    // OAuth2 helpers
    // -------------------------------------------------------------------------

    /**
     * Build the Google OAuth2 authorisation URL.
     */
    public function getAuthorizationUrl(): string
    {
        if (class_exists(\Google_Client::class)) {
            $client = $this->buildGoogleClient();

            return $client->createAuthUrl();
        }

        // Fallback: build URL manually for environments without the library
        $params = [
            'client_id'     => $this->clientId,
            'redirect_uri'  => $this->redirectUri,
            'response_type' => 'code',
            'scope'         => 'https://www.googleapis.com/auth/spreadsheets offline_access',
            'access_type'   => 'offline',
            'prompt'        => 'consent',
        ];

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
    }

    /**
     * Exchange an authorisation code for access + refresh tokens.
     *
     * @throws RuntimeException on empty code or API error
     * @return array{access_token: string, refresh_token?: string, expires_in?: int}
     */
    public function exchangeCodeForTokens(string $code): array
    {
        if ('' === $code) {
            throw new RuntimeException('Authorization code cannot be empty');
        }

        if (class_exists(\Google_Client::class)) {
            $client = $this->buildGoogleClient();
            $tokens = $client->fetchAccessTokenWithAuthCode($code);

            if (isset($tokens['error'])) {
                throw new RuntimeException('Token exchange failed: ' . $tokens['error']);
            }

            return $tokens;
        }

        // Without the library we can only simulate the call in tests.
        // Real usage requires the google/apiclient package.
        throw new RuntimeException('google/apiclient is not installed; cannot exchange token');
    }

    /**
     * Refresh an access token using a stored refresh token.
     *
     * @throws RuntimeException on empty or invalid token
     * @return array{access_token: string, expires_in?: int}
     */
    public function refreshAccessToken(string $refreshToken): array
    {
        if ('' === $refreshToken) {
            throw new RuntimeException('Refresh token cannot be empty');
        }

        if (class_exists(\Google_Client::class)) {
            $client = $this->buildGoogleClient();
            $client->refreshToken($refreshToken);
            $token = $client->getAccessToken();

            if (null === $token || isset($token['error'])) {
                throw new RuntimeException('Access token refresh failed');
            }

            return $token;
        }

        throw new RuntimeException('google/apiclient is not installed; cannot refresh token');
    }

    // -------------------------------------------------------------------------
    // Token encryption
    // -------------------------------------------------------------------------

    /**
     * Encrypt a token string for secure storage.
     *
     * Uses sodium secretbox with a key derived from APP_KEY.
     *
     * @throws RuntimeException on empty input
     */
    public function encryptToken(string $token): string
    {
        if ('' === $token) {
            throw new RuntimeException('Token cannot be empty');
        }

        $key   = $this->deriveEncryptionKey();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box   = sodium_crypto_secretbox($token, $nonce, $key);

        return base64_encode($nonce . $box);
    }

    /**
     * Decrypt a previously encrypted token string.
     *
     * @throws RuntimeException on invalid / tampered ciphertext
     */
    public function decryptToken(string $encrypted): string
    {
        if ('' === $encrypted) {
            throw new RuntimeException('Encrypted token cannot be empty');
        }

        $raw   = base64_decode($encrypted, true);
        if (false === $raw) {
            throw new RuntimeException('Encrypted token is not valid base64');
        }

        $nonceLen = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
        if (strlen($raw) <= $nonceLen) {
            throw new RuntimeException('Encrypted token is too short to be valid');
        }

        $nonce      = substr($raw, 0, $nonceLen);
        $ciphertext = substr($raw, $nonceLen);
        $key        = $this->deriveEncryptionKey();

        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);

        if (false === $plaintext) {
            throw new RuntimeException('Failed to decrypt token; data may be tampered or key changed');
        }

        return $plaintext;
    }

    // -------------------------------------------------------------------------
    // Sheet I/O
    // -------------------------------------------------------------------------

    /**
     * Read rows from a Google Sheet range.
     *
     * @throws RuntimeException on missing parameters or API errors
     * @return list<list<string>>
     */
    public function readSheetRows(string $sheetId, string $range, string $accessToken): array
    {
        if ('' === $sheetId) {
            throw new RuntimeException('Sheet ID cannot be empty');
        }

        if ('' === $accessToken) {
            throw new RuntimeException('Access token cannot be empty');
        }

        if (!class_exists(\Google_Client::class)) {
            throw new RuntimeException('google/apiclient is not installed; cannot read sheet rows');
        }

        $client = $this->buildGoogleClient();
        $client->setAccessToken(['access_token' => $accessToken]);

        $service  = new \Google_Service_Sheets($client);
        $response = $service->spreadsheets_values->get($sheetId, $range);

        return $response->getValues() ?? [];
    }

    /**
     * Append rows to a Google Sheet range.
     *
     * @param  list<list<string|float>>  $rows
     *
     * @throws RuntimeException on missing parameters or empty rows
     */
    public function appendSheetRows(string $sheetId, string $range, array $rows, string $accessToken): void
    {
        if ('' === $sheetId) {
            throw new RuntimeException('Sheet ID cannot be empty');
        }

        if ([] === $rows) {
            throw new RuntimeException('Rows cannot be empty');
        }

        if (!class_exists(\Google_Client::class)) {
            throw new RuntimeException('google/apiclient is not installed; cannot append sheet rows');
        }

        $client = $this->buildGoogleClient();
        $client->setAccessToken(['access_token' => $accessToken]);

        $service = new \Google_Service_Sheets($client);
        $body    = new \Google_Service_Sheets_ValueRange(['values' => $rows]);
        $service->spreadsheets_values->append($sheetId, $range, $body, ['valueInputOption' => 'RAW']);
    }

    // -------------------------------------------------------------------------
    // Field map helpers
    // -------------------------------------------------------------------------

    /**
     * Validate that a field map contains all required fields and valid column references.
     *
     * Required fields: date, amount, payee.
     * Column references must be single uppercase letters A–Z.
     */
    public function validateFieldMap(array $fieldMap): bool
    {
        if ([] === $fieldMap) {
            return false;
        }

        foreach (self::REQUIRED_FIELDS as $required) {
            if (!array_key_exists($required, $fieldMap)) {
                return false;
            }
        }

        foreach ($fieldMap as $column) {
            if (!preg_match('/^[A-Z]$/', (string) $column)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Build a sheet row array from a transaction, ordered by column reference.
     *
     * @param  array<string, mixed>  $transaction
     * @param  array<string, string> $fieldMap     e.g. ['date' => 'A', 'payee' => 'B', 'amount' => 'C']
     * @return list<mixed>
     */
    public function buildRowFromTransaction(array $transaction, array $fieldMap): array
    {
        // Sort by column letter to maintain sheet order
        asort($fieldMap);

        $row = [];
        foreach ($fieldMap as $field => $column) {
            $row[] = $transaction[$field] ?? '';
        }

        return $row;
    }

    /**
     * Parse a sheet row into a transaction array using the field map.
     *
     * Returns null when the row does not have enough columns.
     *
     * @param  list<string>          $row
     * @param  array<string, string> $fieldMap  e.g. ['date' => 'A', 'payee' => 'B', 'amount' => 'C']
     * @return null|array<string, mixed>
     */
    public function parseRowToTransaction(array $row, array $fieldMap): ?array
    {
        // Map column letter to zero-based index
        $columnIndex = static fn (string $col): int => ord(strtoupper($col)) - ord('A');

        $maxRequired = 0;
        foreach (self::REQUIRED_FIELDS as $field) {
            if (!isset($fieldMap[$field])) {
                return null;
            }
            $idx = $columnIndex($fieldMap[$field]);
            if ($idx > $maxRequired) {
                $maxRequired = $idx;
            }
        }

        if (count($row) <= $maxRequired) {
            return null;
        }

        $transaction = [];
        foreach ($fieldMap as $field => $col) {
            $idx   = $columnIndex($col);
            $value = $row[$idx] ?? null;

            // Cast amount to float
            if ('amount' === $field && null !== $value) {
                $value = (float) str_replace(',', '', (string) $value);
            }

            $transaction[$field] = $value;
        }

        return $transaction;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function buildGoogleClient(): \Google_Client
    {
        $client = new \Google_Client();
        $client->setClientId($this->clientId);
        $client->setClientSecret($this->clientSecret);
        $client->setRedirectUri($this->redirectUri);
        $client->setScopes([\Google_Service_Sheets::SPREADSHEETS]);
        $client->setAccessType('offline');
        $client->setPrompt('consent');

        return $client;
    }

    /**
     * Derive a 32-byte encryption key from the application key.
     *
     * Falls back to a key derived from the client credentials when the
     * Laravel config helper is unavailable (e.g. in plain PHPUnit unit tests).
     */
    private function deriveEncryptionKey(): string
    {
        // Use Laravel's config() helper when available
        $appKey = function_exists('config') ? config('app.key', '') : '';

        // Fall back to a deterministic key derived from constructor params
        if ('' === $appKey) {
            $appKey = $this->clientId . ':' . $this->clientSecret;
        }

        // Remove 'base64:' prefix if present (Laravel stores keys that way)
        if (str_starts_with($appKey, 'base64:')) {
            $appKey = base64_decode(substr($appKey, 7), true) ?: $appKey;
        }

        return substr(hash('sha256', (string) $appKey, true), 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}
