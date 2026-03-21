<?php

declare(strict_types=1);

namespace FireflyIII\Extensions\Import\Services;

use RuntimeException;

/**
 * Parses raw PDF bank statement content into structured lines and transaction blocks.
 *
 * Uses Smalot\PdfParser when available (installed via smalot/pdfparser); falls back to
 * header-detection when the library is absent (test environments).
 */
class PDFStatementParser
{
    private int    $linesParsed    = 0;
    private int    $blocksFound    = 0;
    private string $formatDetected = 'generic';

    /**
     * Extract raw text from PDF binary content.
     *
     * @throws RuntimeException when content is empty or cannot be parsed as PDF
     */
    public function extractText(string $pdfContent): string
    {
        if ('' === $pdfContent) {
            throw new RuntimeException('PDF content is empty');
        }

        // Validate minimal PDF header
        if (!str_starts_with($pdfContent, '%PDF-')) {
            throw new RuntimeException('Invalid PDF content: missing PDF header');
        }

        // Use Smalot\PdfParser when available
        if (class_exists(\Smalot\PdfParser\Parser::class)) {
            try {
                $parser   = new \Smalot\PdfParser\Parser();
                $pdf      = $parser->parseContent($pdfContent);

                return $pdf->getText();
            } catch (\Exception $e) {
                throw new RuntimeException('Failed to parse PDF: ' . $e->getMessage(), 0, $e);
            }
        }

        // Fallback: extract readable ASCII text from PDF binary
        // This is a best-effort extraction for environments without the library
        $text = '';
        if (preg_match_all('/\(([^\)]+)\)/', $pdfContent, $matches)) {
            $text = implode(' ', $matches[1]);
        }

        return $text;
    }

    /**
     * Split raw text into trimmed, non-empty lines.
     *
     * @return list<string>
     */
    public function parseLines(string $text): array
    {
        if ('' === $text) {
            return [];
        }

        // Normalise Windows line-endings
        $normalised = str_replace("\r\n", "\n", $text);
        $rawLines   = explode("\n", $normalised);

        $lines = [];
        foreach ($rawLines as $line) {
            $trimmed = trim($line);
            if ('' !== $trimmed) {
                $lines[] = $trimmed;
            }
        }

        $this->linesParsed = count($lines);

        return $lines;
    }

    /**
     * Detect the bank format from statement text.
     *
     * Returns a format identifier string: 'chase', 'bank_of_america', 'wells_fargo', or 'generic'.
     */
    public function detectBankFormat(string $text): string
    {
        $lower = strtolower($text);

        $format = match (true) {
            str_contains($lower, 'chase bank')         => 'chase',
            str_contains($lower, 'bank of america')    => 'bank_of_america',
            str_contains($lower, 'wells fargo')        => 'wells_fargo',
            default                                     => 'generic',
        };

        $this->formatDetected = $format;

        return $format;
    }

    /**
     * Split statement text into individual transaction blocks.
     *
     * A "block" is a line that appears to contain a transaction (starts with a date pattern).
     *
     * @return list<string>
     */
    public function splitIntoTransactionBlocks(string $text): array
    {
        if ('' === $text) {
            return [];
        }

        $lines  = $this->parseLines($text);
        $blocks = [];

        // Transaction lines typically start with a date pattern
        $datePattern = '/^\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4}|\d{4}[\/\-]\d{2}[\/\-]\d{2}|^(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\s+\d{1,2}/i';

        foreach ($lines as $line) {
            if (preg_match($datePattern, $line)) {
                $blocks[] = $line;
            }
        }

        $this->blocksFound = count($blocks);

        return $blocks;
    }

    /**
     * Normalise an amount string to a float value.
     *
     * Supports:
     *  - Dollar sign prefix: $1,234.56
     *  - Parentheses for negative: (456.78)
     *  - Minus sign: -123.45
     *  - European format: 1.234,56
     *
     * @throws RuntimeException when the string cannot be interpreted as a number
     */
    public function normalizeAmount(string $input): float
    {
        $trimmed = trim($input);

        // Detect negative via parentheses
        $negative = false;
        if (str_starts_with($trimmed, '(') && str_ends_with($trimmed, ')')) {
            $negative = true;
            $trimmed  = substr($trimmed, 1, -1);
        }

        // Remove currency symbols and spaces
        $trimmed = str_replace(['$', '€', '£', ' '], '', $trimmed);

        // Detect European format (1.234,56) vs US format (1,234.56)
        if (preg_match('/^\-?\d{1,3}(\.\d{3})+(,\d{2})?$/', $trimmed)) {
            // European: dots are thousand separators, comma is decimal
            $trimmed = str_replace('.', '', $trimmed);
            $trimmed = str_replace(',', '.', $trimmed);
        } else {
            // US format: commas are thousand separators
            $trimmed = str_replace(',', '', $trimmed);
        }

        if (!is_numeric($trimmed)) {
            throw new RuntimeException(sprintf('Cannot parse amount from input: "%s"', $input));
        }

        $value = (float) $trimmed;

        return $negative ? -abs($value) : $value;
    }

    /**
     * Return parsing statistics from the last parse run.
     *
     * @return array{lines_parsed: int, blocks_found: int, format_detected: string}
     */
    public function getStats(): array
    {
        return [
            'lines_parsed'    => $this->linesParsed,
            'blocks_found'    => $this->blocksFound,
            'format_detected' => $this->formatDetected,
        ];
    }
}
