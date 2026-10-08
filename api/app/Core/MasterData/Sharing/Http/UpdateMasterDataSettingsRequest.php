<?php

namespace App\Core\MasterData\Sharing\Http;

use App\Core\MasterData\Sharing\MasterDataSharing;
use App\Core\Rbac\Scope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * TEN-08: switch one data type's sharing mode (`core.master_data_settings.edit`
 * at tenant scope). To per_company: `assign_to_company_id` names the company
 * that receives every record with none. To shared: `confirm: true`.
 */
class UpdateMasterDataSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('core.master_data_settings.edit', Scope::tenant());
    }

    public function rules(): array
    {
        return [
            'data_type' => ['required', 'string', Rule::in(MasterDataSharing::DATA_TYPES)],
            'mode' => ['required', 'string', Rule::in(MasterDataSharing::MODES)],
            'assign_to_company_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('companies', 'id')->whereNull('archived_at')],
            'confirm' => ['sometimes', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'data_type' => __('core.master_data.attributes.data_type'),
            'mode' => __('core.master_data.attributes.mode'),
            'assign_to_company_id' => __('core.master_data.attributes.assign_to_company'),
            'confirm' => __('core.master_data.attributes.confirm'),
        ];
    }
}
