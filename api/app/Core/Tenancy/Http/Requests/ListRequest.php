<?php

namespace App\Core\Tenancy\Http\Requests;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;

/**
 * List filters for companies, branches and locations: `?status=active`
 * (default), `archived` or `all` (TEN-06), and `?per_page` (50, at most 200).
 * Visibility is applied by the controller (RBAC-04).
 */
class ListRequest extends FormRequest
{
    public const PER_PAGE = 50;

    public const MAX_PER_PAGE = 200;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', 'in:active,archived,all'],
            'per_page' => ['sometimes', 'integer', 'between:1,'.self::MAX_PER_PAGE],
        ];
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', self::PER_PAGE);
    }

    /** Filter an archivable query by `?status`. */
    public function applyStatus(Builder $query): Builder
    {
        return match ($this->validated('status', 'active')) {
            'active' => $query->whereNull($query->qualifyColumn('archived_at')),
            'archived' => $query->whereNotNull($query->qualifyColumn('archived_at')),
            default => $query,
        };
    }
}
