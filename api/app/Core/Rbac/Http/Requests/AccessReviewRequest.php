<?php

namespace App\Core\Rbac\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GET access-review: `core.access_review.view` (JSON, paginated) or
 * `core.access_review.export` (`?format=csv`), anywhere; rows are filtered
 * to the user's scope for that permission (RBAC-04).
 */
class AccessReviewRequest extends FormRequest
{
    public const PER_PAGE = 50;

    public const MAX_PER_PAGE = 200;

    public function authorize(): bool
    {
        return $this->user()->can($this->permission());
    }

    public function rules(): array
    {
        return [
            'format' => ['sometimes', 'string', 'in:json,csv'],
            'per_page' => ['sometimes', 'integer', 'between:1,'.self::MAX_PER_PAGE],
        ];
    }

    public function wantsCsv(): bool
    {
        return $this->query('format') === 'csv';
    }

    public function permission(): string
    {
        return $this->wantsCsv() ? 'core.access_review.export' : 'core.access_review.view';
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', self::PER_PAGE);
    }
}
