<?php

declare(strict_types=1);

namespace FireflyIII\Extensions\AI\Enums;

enum AIProvider: string
{
    case OPENAI    = 'openai';
    case ANTHROPIC = 'anthropic';
}
