<?php

namespace Modules\FixedAssets\Http\Requests;

use App\Core\Rbac\ScopeResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST fixed-assets/labels (FA-25): `fixed_assets.label.print`;
 * `{labels: [{asset_id, copies}], format: html|pdf}`; at most 500 labels
 * in one print; each asset must be one the reader sees.
 */
class PrintLabelsRequest extends FormRequest
{
    public const MAX_LABELS = 500;

    public function authorize(): bool
    {
        return app(ScopeResolver::class)->can($this->user(), 'fixed_assets.label.print');
    }

    public function rules(): array
    {
        return [
            'labels' => ['required', 'array', 'min:1', 'max:200'],
            'labels.*.asset_id' => ['required', 'uuid', Rule::exists('fa_assets', 'id')],
            'labels.*.copies' => ['required', 'integer', 'between:1,'.self::MAX_LABELS],
            'format' => ['sometimes', 'string', 'in:html,pdf'],
        ];
    }
}
