<?php

declare(strict_types=1);

namespace FireflyIII\Extensions\AI\Exceptions;

/**
 * Thrown when the AI provider responds with HTTP 429 (rate-limit / quota exceeded).
 * Callers should handle this by skipping categorisation without failing the
 * parent transaction.
 */
class AIQuotaExceededException extends AIServiceException {}
