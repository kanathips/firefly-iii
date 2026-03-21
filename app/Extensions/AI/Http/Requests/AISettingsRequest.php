<?php

declare(strict_types=1);

namespace FireflyIII\Extensions\AI\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the request body for POST /v1/ext/ai/settings.
 */
class AISettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'provider'   => ['required', 'string', 'in:openai,anthropic'],
            'api_key'    => ['required', 'string', 'min:10', 'max:512'],
            'is_enabled' => ['required', 'boolean'],
        ];
    }
}
