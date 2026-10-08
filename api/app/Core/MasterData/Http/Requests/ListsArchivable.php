<?php

namespace App\Core\MasterData\Http\Requests;

use Illuminate\Database\Eloquent\Builder;

/**
 * List filters for archivable master data: `?status=active` (default),
 * `archived` or `all` (TEN-06), and `?per_page` (50, at most 200).
 */
trait ListsArchivable
{
    /** @return array<string, list<string>> */
    protected function listRules(): array
    {
        return [
            'status' => ['sometimes', 'string', 'in:active,archived,all'],
            'per_page' => ['sometimes', 'integer', 'between:1,200'],
        ];
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', 50);
    }

    public function applyStatus(Builder $query): Builder
    {
        return match ($this->validated('status', 'active')) {
            'active' => $query->whereNull($query->qualifyColumn('archived_at')),
            'archived' => $query->whereNotNull($query->qualifyColumn('archived_at')),
            default => $query,
        };
    }
}
