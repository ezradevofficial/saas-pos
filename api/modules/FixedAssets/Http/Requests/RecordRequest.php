<?php

namespace Modules\FixedAssets\Http\Requests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Modules\FixedAssets\Models\Asset;
use Modules\FixedAssets\Models\Category;
use Modules\FixedAssets\Models\DepreciationRun;
use Modules\FixedAssets\Models\ItemCategory;
use Modules\FixedAssets\Support\Access;

/**
 * FA-29, RBAC-04: a request on one record of the module, bound from the
 * route under RLS (another tenant's id is not found). Records of an asset
 * (documents, transfers, disposals, schedules, work orders) are not found
 * unless the user holds the view permission at the asset's location, then
 * need the action's permission there (403). Company records (categories,
 * flagged items, runs) need the permission at their company.
 */
abstract class RecordRequest extends FormRequest
{
    /** The route parameter of the record. */
    abstract protected function parameter(): string;

    /** The view permission (404 without it), or null for company records. */
    abstract protected function viewPermission(): ?string;

    /** The action's permission, or null for a read. */
    protected function permission(): ?string
    {
        return null;
    }

    public function authorize(): bool
    {
        $access = app(Access::class);
        $record = $this->record();

        if ($record instanceof Category || $record instanceof ItemCategory || $record instanceof DepreciationRun) {
            return $access->atCompany($this->user(), (string) ($this->permission() ?? $this->viewPermission()), $record->company_id);
        }

        $asset = $this->asset();
        abort_unless($asset !== null && $access->at($this->user(), (string) $this->viewPermission(), $asset), 404);

        return $this->permission() === null || $access->at($this->user(), $this->permission(), $asset);
    }

    public function rules(): array
    {
        return [];
    }

    public function record(): Model
    {
        return $this->route($this->parameter());
    }

    public function asset(): ?Asset
    {
        return Asset::query()->find($this->record()->getAttribute('asset_id'));
    }
}
