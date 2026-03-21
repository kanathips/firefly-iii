<?php

declare(strict_types=1);

namespace FireflyIII\Extensions\GoogleSheets\Http\Controllers;

use FireflyIII\Extensions\GoogleSheets\Models\SheetConnection;
use FireflyIII\Extensions\GoogleSheets\Models\SheetSyncLog;
use FireflyIII\Extensions\GoogleSheets\Services\GoogleSheetsConnector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

/**
 * Manages Google Sheets OAuth2 connections and sheet sync configuration.
 *
 * Routes:
 *   GET  /v1/ext/sheets/auth/url         – get OAuth2 authorisation URL
 *   GET  /v1/ext/sheets/auth/callback    – exchange code for tokens (unauthenticated)
 *   GET  /v1/ext/sheets/connections      – list user's connections
 *   POST /v1/ext/sheets/connections      – create a new connection
 *   DELETE /v1/ext/sheets/connections/{id} – deactivate a connection
 */
class GoogleSheetsController extends Controller
{
    public function __construct(private readonly GoogleSheetsConnector $connector) {}

    // -------------------------------------------------------------------------
    // GET /v1/ext/sheets/auth/url
    // -------------------------------------------------------------------------

    public function authUrl(): JsonResponse
    {
        $url = $this->connector->getAuthorizationUrl();

        return response()->json([
            'data' => ['url' => $url],
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /v1/ext/sheets/auth/callback
    // -------------------------------------------------------------------------

    /**
     * Handle the OAuth2 callback: exchange the code for tokens and store the connection.
     */
    public function authCallback(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code'  => 'required|string',
            'state' => 'sometimes|string',
        ]);

        try {
            $tokens = $this->connector->exchangeCodeForTokens($validated['code']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => 'OAuth exchange failed: ' . $e->getMessage()], 422);
        }

        $encryptedToken = $this->connector->encryptToken(json_encode($tokens, JSON_THROW_ON_ERROR));

        $connection = SheetConnection::create([
            'user_id'      => (int) Auth::id(),
            'google_token' => $encryptedToken,
            'sheet_id'     => '',
            'field_map'    => json_encode(['date' => 'A', 'payee' => 'B', 'amount' => 'C'], JSON_THROW_ON_ERROR),
            'direction'    => 'import',
            'is_active'    => true,
        ]);

        return response()->json([
            'data' => [
                'id'        => $connection->id,
                'direction' => $connection->direction,
                'is_active' => $connection->is_active,
            ],
        ], 201);
    }

    // -------------------------------------------------------------------------
    // GET /v1/ext/sheets/connections
    // -------------------------------------------------------------------------

    public function listConnections(): JsonResponse
    {
        $connections = SheetConnection::where('user_id', Auth::id())
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(static fn (SheetConnection $c): array => [
                'id'             => $c->id,
                'sheet_id'       => $c->sheet_id,
                'direction'      => $c->direction,
                'is_active'      => $c->is_active,
                'last_synced_at' => $c->last_synced_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $connections]);
    }

    // -------------------------------------------------------------------------
    // POST /v1/ext/sheets/connections
    // -------------------------------------------------------------------------

    public function storeConnection(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sheet_id'  => 'required|string',
            'field_map' => 'required|array',
            'direction' => 'required|string|in:import,export,bidirectional',
        ]);

        if (!$this->connector->validateFieldMap($validated['field_map'])) {
            return response()->json([
                'message' => 'Invalid field map: must include date, amount, and payee with valid column letters.',
            ], 422);
        }

        // Lookup the most recently created token-only connection for this user
        $pending = SheetConnection::where('user_id', Auth::id())
            ->where('sheet_id', '')
            ->latest()
            ->first();

        if (null === $pending) {
            return response()->json(['message' => 'No pending OAuth connection found. Please authenticate first.'], 422);
        }

        $pending->update([
            'sheet_id'  => $validated['sheet_id'],
            'field_map' => json_encode($validated['field_map'], JSON_THROW_ON_ERROR),
            'direction' => $validated['direction'],
        ]);

        return response()->json([
            'data' => [
                'id'        => $pending->id,
                'sheet_id'  => $pending->sheet_id,
                'direction' => $pending->direction,
                'is_active' => $pending->is_active,
            ],
        ], 201);
    }

    // -------------------------------------------------------------------------
    // DELETE /v1/ext/sheets/connections/{id}
    // -------------------------------------------------------------------------

    public function destroyConnection(int $id): JsonResponse
    {
        $connection = SheetConnection::where('user_id', Auth::id())->find($id);

        if (null === $connection) {
            return response()->json(['message' => 'Connection not found'], 404);
        }

        $connection->update(['is_active' => false]);
        $connection->delete();

        return response()->json(null, 204);
    }
}
