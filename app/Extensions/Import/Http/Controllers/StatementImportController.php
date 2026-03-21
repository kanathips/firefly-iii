<?php

declare(strict_types=1);

namespace FireflyIII\Extensions\Import\Http\Controllers;

use Carbon\Carbon;
use FireflyIII\Extensions\Import\Models\TransactionPreview;
use FireflyIII\Extensions\Import\Services\OCRExtractor;
use FireflyIII\Extensions\Import\Services\PDFStatementParser;
use FireflyIII\Models\TransactionJournal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Handles PDF bank statement import via a three-step flow:
 *   1. POST /import/pdf/parse     – upload & parse into a preview
 *   2. GET  /import/pdf/preview/{id} – retrieve the preview for review
 *   3. POST /import/pdf/confirm   – confirm and batch-create transactions
 */
class StatementImportController extends Controller
{
    private const PREVIEW_TTL     = 3600;   // 1 hour
    private const MAX_FILE_SIZE_KB = 10 * 1024; // 10 MB

    public function __construct(
        private readonly PDFStatementParser $parser,
        private readonly OCRExtractor       $extractor,
    ) {}

    // -------------------------------------------------------------------------
    // POST /v1/ext/import/pdf/parse
    // -------------------------------------------------------------------------

    /**
     * Accept a PDF upload, parse it, detect duplicates and store a preview in cache.
     */
    public function parse(Request $request): JsonResponse
    {
        $this->validateParseRequest($request);

        $pdfContent = $request->file('file')->get();

        $text   = $this->parser->extractText($pdfContent);
        $lines  = $this->parser->parseLines($text);
        $this->parser->detectBankFormat($text);

        $parsed     = $this->extractor->parseMultipleLines($lines);
        $duplicates = $this->detectDuplicates($parsed, (int) Auth::id());

        $previewId = Str::uuid()->toString();
        $preview   = new TransactionPreview(
            userId:       (int) Auth::id(),
            transactions: $parsed,
            duplicates:   $duplicates,
        );

        Cache::put('import_preview_' . $previewId, $preview->toArray(), self::PREVIEW_TTL);

        return response()->json([
            'data' => [
                'preview_id'        => $previewId,
                'transaction_count' => $preview->transactionCount(),
                'duplicate_count'   => $preview->duplicateCount(),
                'format'            => $this->parser->getStats()['format_detected'],
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /v1/ext/import/pdf/preview/{id}
    // -------------------------------------------------------------------------

    /**
     * Return the transactions in a preview so the user can review and edit before import.
     */
    public function preview(string $previewId): JsonResponse
    {
        $cached = Cache::get('import_preview_' . $previewId);

        if (null === $cached) {
            return response()->json(['message' => 'Preview not found or expired'], 404);
        }

        $preview = TransactionPreview::fromArray($cached);

        if ($preview->userId !== (int) Auth::id()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json([
            'data' => [
                'transactions'      => $preview->transactions,
                'duplicates'        => $preview->duplicates,
                'transaction_count' => $preview->transactionCount(),
                'duplicate_count'   => $preview->duplicateCount(),
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /v1/ext/import/pdf/confirm
    // -------------------------------------------------------------------------

    /**
     * Confirm an import: create transactions from the preview (excluding skipped rows).
     */
    public function confirm(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'preview_id'    => 'required|string',
            'account_id'    => 'sometimes|integer',
            'currency_code' => 'sometimes|string|size:3',
        ]);

        $cached = Cache::get('import_preview_' . $validated['preview_id']);

        if (null === $cached) {
            return response()->json(['message' => 'Preview not found or expired'], 404);
        }

        $preview = TransactionPreview::fromArray($cached);

        if ($preview->userId !== (int) Auth::id()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $imported = 0;
        $skipped  = 0;
        $errors   = [];

        foreach ($preview->transactions as $index => $txn) {
            if (true === ($txn['skip'] ?? false)) {
                ++$skipped;

                continue;
            }

            try {
                $this->createTransaction($txn, $validated);
                ++$imported;
            } catch (\Throwable $e) {
                $errors[] = ['index' => $index, 'message' => $e->getMessage()];
            }
        }

        // Invalidate the preview after confirm
        Cache::forget('import_preview_' . $validated['preview_id']);

        return response()->json([
            'data' => [
                'imported' => $imported,
                'skipped'  => $skipped,
                'errors'   => $errors,
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Validate the parse request (file must be a PDF, max 10 MB).
     *
     * @throws ValidationException
     */
    private function validateParseRequest(Request $request): void
    {
        $request->validate([
            'file' => [
                'required',
                'file',
                'mimes:pdf',
                'max:' . self::MAX_FILE_SIZE_KB,
            ],
        ]);
    }

    /**
     * Check parsed transactions against the database and return those that look like duplicates.
     *
     * Matches by date ± 2 days AND amount within ±$0.01.
     *
     * @param  list<array{date: ?string, amount: float, is_debit: bool}>  $transactions
     * @return list<array{date: ?string, amount: float}>
     */
    private function detectDuplicates(array $transactions, int $userId): array
    {
        $duplicates = [];

        foreach ($transactions as $txn) {
            if (null === ($txn['date'] ?? null)) {
                continue;
            }

            $date   = Carbon::parse($txn['date']);
            $amount = (float) $txn['amount'];

            $exists = TransactionJournal::where('user_id', $userId)
                ->whereBetween('date', [
                    $date->copy()->subDays(2)->toDateString(),
                    $date->copy()->addDays(2)->toDateString(),
                ])
                ->whereHas('transactions', function ($q) use ($amount): void {
                    $q->whereBetween('amount', [
                        $amount - 0.01,
                        $amount + 0.01,
                    ]);
                })
                ->exists();

            if ($exists) {
                $duplicates[] = $txn;
            }
        }

        return $duplicates;
    }

    /**
     * Create a single transaction journal from parsed data.
     *
     * This is intentionally minimal; a full implementation would call
     * the existing TransactionStoreRequest / journal creation pipeline.
     *
     * @param  array{date: ?string, payee: ?string, amount: float, is_debit: bool}  $txn
     * @param  array{account_id?: int, currency_code?: string}                       $options
     */
    private function createTransaction(array $txn, array $options): void
    {
        // In the full implementation this would delegate to the existing
        // TransactionJournal creation service / pipeline.  Here we validate
        // that the minimum required fields are present.
        if (null === ($txn['date'] ?? null) || '' === ($txn['date'] ?? '')) {
            throw new \InvalidArgumentException('Transaction date is required');
        }

        if (!isset($txn['amount']) || $txn['amount'] <= 0) {
            throw new \InvalidArgumentException('Transaction amount must be positive');
        }

        // Actual DB write would happen here via an injected service.
        // Left as a no-op stub so the controller can be tested end-to-end
        // without coupling to the full transaction creation pipeline.
    }
}
