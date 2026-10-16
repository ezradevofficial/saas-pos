<?php

namespace Modules\FixedAssets\Http\Requests;

use App\Core\Rbac\ScopeResolver;
use App\Core\Tenancy\Http\Requests\ListRequest as CoreListRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\FixedAssets\Support\Access;

/**
 * FA-26, FA-29, ADR 008: the module's lists: rows where the reader holds
 * the list's permission (at the asset's location for asset records, at the
 * company for company records), filtered by `?company=`, `?branch=`,
 * `?location=`, `?asset=`, `?category=` (ids checked under RLS: another
 * tenant's id does not exist), `?status=` (or `all`), `?search=`,
 * `?from=` and `?to=` (days), with pages.
 */
abstract class ListRequest extends FormRequest
{
    abstract protected function viewPermission(): string;

    /** @return list<string> */
    protected function statuses(): array
    {
        return [];
    }

    public function authorize(): bool
    {
        return app(ScopeResolver::class)->can($this->user(), $this->viewPermission());
    }

    public function rules(): array
    {
        return [
            ...($this->statuses() === [] ? [] : ['status' => ['sometimes', 'string', Rule::in([...$this->statuses(), 'all'])]]),
            'company' => ['sometimes', 'uuid', Rule::exists('companies', 'id')],
            'branch' => ['sometimes', 'uuid', Rule::exists('branches', 'id')],
            'location' => ['sometimes', 'uuid', Rule::exists('locations', 'id')],
            'asset' => ['sometimes', 'uuid', Rule::exists('fa_assets', 'id')],
            'category' => ['sometimes', 'uuid', Rule::exists('fa_categories', 'id')],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'per_page' => ['sometimes', 'integer', 'between:1,'.CoreListRequest::MAX_PER_PAGE],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /** Rows of a table with `asset_id` (or the assets themselves) at locations where the reader holds the permission, and the filters. */
    public function applyAssetFilters(Builder $query, ?string $dateColumn = null, bool $assets = false): Builder
    {
        $access = app(Access::class);
        $assets ? $access->byLocation($query, $this->user(), $this->viewPermission()) : $access->byAsset($query, $this->user(), $this->viewPermission());
        $assetQuery = fn (callable $where) => $assets ? $where($query) : $query->whereIn($query->qualifyColumn('asset_id'), fn ($q) => $where($q->select('id')->from('fa_assets')));

        foreach (['branch' => 'branch_id', 'location' => 'location_id', 'category' => 'category_id'] as $filter => $column) {
            if ($this->filled($filter)) {
                $assetQuery(fn ($q) => $q->where($column, $this->validated($filter)));
            }
        }

        // Through the asset: schedules, work orders and readings carry no company of their own.
        if ($this->filled('company')) {
            $assetQuery(fn ($q) => $q->where('company_id', $this->validated('company')));
        }

        if ($this->filled('asset')) {
            $query->where($query->qualifyColumn($assets ? 'id' : 'asset_id'), $this->validated('asset'));
        }

        return $this->applyCommon($query, $dateColumn);
    }

    /** Rows of a company table where the reader holds the permission at the company, and the filters. */
    public function applyCompanyFilters(Builder $query, ?string $dateColumn = null): Builder
    {
        app(Access::class)->byCompany($query, $this->user(), $this->viewPermission());

        if ($this->filled('company')) {
            $query->where($query->qualifyColumn('company_id'), $this->validated('company'));
        }

        return $this->applyCommon($query, $dateColumn);
    }

    /** Rows of a company table of the companies the reader works in (holds the permission anywhere in them, TEN-08), and the filters. */
    public function applyTouchedCompanies(Builder $query): Builder
    {
        $touched = app(ScopeResolver::class)->visibleIds($this->user(), $this->viewPermission())->companiesTouched();

        if ($touched !== null) {
            $query->whereIn($query->qualifyColumn('company_id'), $touched);
        }

        if ($this->filled('company')) {
            $query->where($query->qualifyColumn('company_id'), $this->validated('company'));
        }

        return $query;
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', CoreListRequest::PER_PAGE);
    }

    private function applyCommon(Builder $query, ?string $dateColumn): Builder
    {
        if ($this->statuses() !== [] && $this->filled('status') && $this->validated('status') !== 'all') {
            $query->where($query->qualifyColumn('status'), $this->validated('status'));
        }

        if ($dateColumn !== null && $this->filled('from')) {
            $query->where($query->qualifyColumn($dateColumn), '>=', $this->validated('from'));
        }

        if ($dateColumn !== null && $this->filled('to')) {
            $query->where($query->qualifyColumn($dateColumn), '<=', $this->validated('to'));
        }

        return $query;
    }
}
