<?php

declare(strict_types=1);

namespace FireflyIII\Extensions\AI\Exceptions;

use RuntimeException;

/**
 * Thrown when the AI provider returns an unexpected error response or the
 * HTTP connection itself fails.
 */
class AIServiceException extends RuntimeException {}
