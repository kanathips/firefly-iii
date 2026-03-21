<?php

declare(strict_types=1);

namespace FireflyIII\Extensions\Import\Services;

/**
 * Extracts structured fields (date, amount, payee) from raw bank statement lines
 * using regex-based pattern matching.
 */
class OCRExtractor
{
    private const CONFIDENCE_THRESHOLD = 0.5;

    // Date patterns ordered from most to least specific
    private const DATE_PATTERNS = [
        // YYYY-MM-DD (ISO 8601)
        '/\b(\d{4})[-\/](\d{2})[-\/](\d{2})\b/'                                  => 'ymd',
        // MM/DD/YYYY or MM-DD-YYYY (ambiguous but treated as M/D/Y)
        '/\b(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})\b/'                            => 'ambiguous',
        // Month DD YYYY (Jan 15 2024)
        '/\b(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\s+(\d{1,2})\s+(\d{4})\b/i' => 'Mon_d_Y',
        // DD Mon YYYY (15 Jan 2024)
        '/\b(\d{1,2})\s+(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\s+(\d{4})\b/i' => 'd_Mon_Y',
    ];

    private const MONTH_MAP = [
        'jan' => '01', 'feb' => '02', 'mar' => '03', 'apr' => '04',
        'may' => '05', 'jun' => '06', 'jul' => '07', 'aug' => '08',
        'sep' => '09', 'oct' => '10', 'nov' => '11', 'dec' => '12',
    ];

    /**
     * Extract a date string (YYYY-MM-DD) from a transaction line.
     *
     * Returns null if no recognisable date is found.
     */
    public function extractDate(string $line): ?string
    {
        if ('' === $line) {
            return null;
        }

        foreach (self::DATE_PATTERNS as $pattern => $type) {
            if (!preg_match($pattern, $line, $m)) {
                continue;
            }

            return match ($type) {
                'ymd'       => sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]),
                'ambiguous' => $this->resolveAmbiguousDate((int) $m[1], (int) $m[2], (int) $m[3]),
                'Mon_d_Y'   => sprintf('%04d-%02d-%02d', (int) $m[3], (int) self::MONTH_MAP[strtolower(substr($m[1], 0, 3))], (int) $m[2]),
                'd_Mon_Y'   => sprintf('%04d-%02d-%02d', (int) $m[3], (int) self::MONTH_MAP[strtolower(substr($m[2], 0, 3))], (int) $m[1]),
                default     => null,
            };
        }

        return null;
    }

    /**
     * Extract an amount from a transaction line.
     *
     * Returns an array with:
     *   - 'amount'   => float  (absolute value)
     *   - 'is_debit' => bool   (true when the amount is negative / a debit)
     *
     * Returns null if no amount is found.
     *
     * @return null|array{amount: float, is_debit: bool}
     */
    public function extractAmount(string $line): ?array
    {
        if ('' === $line) {
            return null;
        }

        // Pattern: optional +/- or parentheses, optional $, digits with optional commas, decimal part
        $pattern = '/(?:^|[\s,])([-+]?\$?[\d,]+\.\d{2}|\(\$?[\d,]+\.\d{2}\))(?:$|[\s,])/';

        if (!preg_match($pattern, $line, $matches)) {
            return null;
        }

        $raw      = trim($matches[1]);
        $isDebit  = false;

        // Parentheses → debit
        if (str_starts_with($raw, '(') && str_ends_with($raw, ')')) {
            $isDebit = true;
            $raw     = substr($raw, 1, -1);
        } elseif (str_starts_with($raw, '-')) {
            $isDebit = true;
        }

        // Strip non-numeric except dot
        $clean  = preg_replace('/[^0-9.]/', '', $raw);
        $amount = (float) $clean;

        if (0.0 === $amount && '0.00' !== $clean && '0' !== $clean) {
            return null;
        }

        return [
            'amount'   => $amount,
            'is_debit' => $isDebit,
        ];
    }

    /**
     * Extract the payee description from a transaction line.
     *
     * Strips the leading date and trailing amount, returning the middle portion.
     *
     * Returns null when the line is empty or only contains a date and amount.
     */
    public function extractPayee(string $line): ?string
    {
        if ('' === $line) {
            return null;
        }

        // Remove leading date
        $withoutDate = preg_replace(
            '/^\s*(\d{4}[-\/]\d{2}[-\/]\d{2}|\d{1,2}[-\/]\d{1,2}[-\/]\d{2,4}|(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\s+\d{1,2}\s+\d{4}|\d{1,2}\s+(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\s+\d{4})\s*/i',
            '',
            $line
        );

        // Remove trailing amount (optional sign, optional $, digits, decimal)
        $withoutAmount = preg_replace('/\s*[-+]?\(?\$?[\d,]+\.\d{2}\)?\s*$/', '', $withoutDate ?? '');

        $payee = trim($withoutAmount ?? '');

        return '' === $payee ? null : $payee;
    }

    /**
     * Parse a single transaction line into all structured fields.
     *
     * Returns null when the line does not appear to be a transaction line
     * (i.e., no amount found).
     *
     * @return null|array{date: ?string, payee: ?string, amount: float, is_debit: bool, confidence: float}
     */
    public function parseTransactionLine(string $line): ?array
    {
        if ('' === $line) {
            return null;
        }

        $amountData = $this->extractAmount($line);
        if (null === $amountData) {
            return null;
        }

        $date   = $this->extractDate($line);
        $payee  = $this->extractPayee($line);

        // Confidence: higher when both date and payee are found
        $confidence = 0.5;
        if (null !== $date) {
            $confidence += 0.3;
        }
        if (null !== $payee && '' !== $payee) {
            $confidence += 0.2;
        }

        return [
            'date'       => $date,
            'payee'      => $payee,
            'amount'     => $amountData['amount'],
            'is_debit'   => $amountData['is_debit'],
            'confidence' => $confidence,
        ];
    }

    /**
     * Parse multiple lines, returning only those that look like transactions.
     *
     * @param  list<string>  $lines
     * @return list<array{date: ?string, payee: ?string, amount: float, is_debit: bool, confidence: float}>
     */
    public function parseMultipleLines(array $lines): array
    {
        if ([] === $lines) {
            return [];
        }

        $transactions = [];
        foreach ($lines as $line) {
            $parsed = $this->parseTransactionLine($line);
            if (null !== $parsed) {
                $transactions[] = $parsed;
            }
        }

        return $transactions;
    }

    /**
     * Suggest human-readable corrections for extractions with missing fields or low confidence.
     *
     * Missing required fields (date) are always flagged regardless of confidence score.
     * A general low-confidence warning is added when the score is below the threshold.
     *
     * Returns an empty array when all fields are present and confidence is high.
     *
     * @param  array{date: ?string, payee: ?string, amount: float, is_debit: bool, confidence: float}  $transaction
     * @return list<array{field: string, message: string}>
     */
    public function suggestCorrections(array $transaction): array
    {
        $confidence  = (float) ($transaction['confidence'] ?? 0.0);
        $suggestions = [];

        // Always flag missing date regardless of confidence
        if (null === ($transaction['date'] ?? null)) {
            $suggestions[] = [
                'field'   => 'date',
                'message' => 'Date could not be extracted; please enter a date manually.',
            ];
        }

        // Always flag missing payee regardless of confidence
        if (null === ($transaction['payee'] ?? null) || '' === ($transaction['payee'] ?? '')) {
            $suggestions[] = [
                'field'   => 'payee',
                'message' => 'Payee description is missing; please fill it in.',
            ];
        }

        // For low-confidence records with no other issues, add a general review suggestion
        if ($confidence < self::CONFIDENCE_THRESHOLD && [] === $suggestions) {
            $suggestions[] = [
                'field'   => 'general',
                'message' => sprintf('Low extraction confidence (%.0f%%); please verify this entry.', $confidence * 100),
            ];
        }

        return $suggestions;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve an ambiguous two-number date (A/B/YYYY) to YYYY-MM-DD.
     *
     * When the first number is > 12 it must be the day (DD/MM/YYYY).
     * Otherwise we default to treating it as MM/DD/YYYY (US convention).
     */
    private function resolveAmbiguousDate(int $first, int $second, int $year): string
    {
        if ($first > 12) {
            // DD/MM/YYYY
            return sprintf('%04d-%02d-%02d', $year, $second, $first);
        }

        // MM/DD/YYYY (US default)
        return sprintf('%04d-%02d-%02d', $year, $first, $second);
    }
}
