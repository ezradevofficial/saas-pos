<?php

namespace Modules\FixedAssets\Http\Requests;

use App\Core\Lists\Http\SortsAndExports;
use App\Core\Lists\ListDefinition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Modules\FixedAssets\Http\Lists\AssetList;
use Modules\FixedAssets\Models\Asset;

/**
 * GET fixed-assets/assets (FA-26, LAY-04, EXP-01): `fixed_assets.asset.view`;
 * the register at the locations the reader sees; `?status=` (`draft`,
 * `active`, `disposed`, `archived`, `all`; active and drafts by default),
 * `?company=`, `?branch=`, `?location=`, `?category=`, `?custodian=` (a
 * user), `?search=` (tag, name, serial number), `?sort=`, pages and an
 * export.
 */
class ListAssetsRequest extends ListRequest
{
    use SortsAndExports;

    public function list(): ListDefinition
    {
        return new AssetList;
    }

    protected function viewPermission(): string
    {
        return 'fixed_assets.asset.view';
    }

    public function rules(): array
    {
        return [
            ...parent::rules(),
            ...$this->sortAndExportRules(),
            'status' => ['sometimes', 'string', Rule::in([...Asset::STATUSES, 'archived', 'all'])],
            'custodian' => ['sometimes', 'uuid', Rule::exists('users', 'id')->whereNot('status', 'service')],
        ];
    }

    public function applyRegisterFilters(Builder $query): Builder
    {
        $this->applyAssetFilters($query, assets: true);
        $status = $this->validated('status');

        match ($status) {
            null => $query->whereNull('archived_at')->whereIn('status', [Asset::DRAFT, Asset::ACTIVE]),
            'all' => $query,
            'archived' => $query->whereNotNull('archived_at'),
            default => $query->whereNull('archived_at')->where('status', $status),
        };

        if ($this->filled('custodian')) {
            $query->where('custodian_user_id', $this->validated('custodian'));
        }

        $search = trim((string) $this->validated('search', ''));

        if ($search !== '') {
            $like = '%'.addcslashes($search, '\\%_').'%';
            $query->where(fn (Builder $q) => $q->where('number', 'ilike', $like)->orWhere('name', 'ilike', $like)->orWhere('serial_number', 'ilike', $like));
        }

        return $query;
    }
}
