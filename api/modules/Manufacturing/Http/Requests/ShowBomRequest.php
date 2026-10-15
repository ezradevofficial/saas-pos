<?php

namespace Modules\Manufacturing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Manufacturing\Models\Bom;
use Modules\Manufacturing\Models\BomVersion;
use Modules\Manufacturing\Support\Places;

/**
 * GET manufacturing/boms/{id} and manufacturing/bom-versions/{id}
 * (MFG-21): a BOM (or version) for a holder of `manufacturing.bom.view`
 * anywhere in its company (RBAC-04); anything else is not found, so ids
 * out of scope are never confirmed. Also the base of the BOM actions,
 * which need `manufacturing.bom.manage` in the company (403).
 */
class ShowBomRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_unless($this->sees(), 404);

        return true;
    }

    public function rules(): array
    {
        return [];
    }

    public function bom(): Bom
    {
        $version = $this->route('mfg_bom_version');

        return $version instanceof BomVersion ? Bom::query()->findOrFail($version->bom_id) : $this->route('mfg_bom');
    }

    protected function sees(): bool
    {
        return app(Places::class)->inCompany($this->user(), 'manufacturing.bom.view', $this->bom()->company_id);
    }

    protected function manages(): bool
    {
        return app(Places::class)->inCompany($this->user(), 'manufacturing.bom.manage', $this->bom()->company_id);
    }
}
