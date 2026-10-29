<?php

namespace App\Core\Search\Http\Requests;

use App\Core\Search\SearchTerm;
use Illuminate\Foundation\Http\FormRequest;

/** LAY-01: any signed-in user may search; what they find follows their permissions (RBAC-08). */
class SearchRequest extends FormRequest
{
    public const MAX_PER_TYPE = 5;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->query('q'))) {
            $this->merge(['q' => trim((string) preg_replace('/\s+/u', ' ', $this->query('q')))]);
        }
    }

    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:'.SearchTerm::MIN_LENGTH, 'max:'.SearchTerm::MAX_LENGTH],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_TYPE],
        ];
    }

    public function term(): SearchTerm
    {
        return new SearchTerm($this->validated('q'), (int) ($this->validated('limit') ?? self::MAX_PER_TYPE));
    }
}
