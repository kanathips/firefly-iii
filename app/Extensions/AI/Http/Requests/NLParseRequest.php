<?php

declare(strict_types=1);

namespace FireflyIII\Extensions\AI\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the request body for POST /v1/ext/ai/parse-nl.
 *
 * Max 500 characters enforced here (and also inside NLTransactionParser as
 * defence-in-depth).
 */
class NLParseRequest extends FormRequest
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
            'input' => ['required', 'string', 'min:1', 'max:500'],
        ];
    }
}
