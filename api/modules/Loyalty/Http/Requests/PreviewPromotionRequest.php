<?php

namespace Modules\Loyalty\Http\Requests;

use App\Core\MasterData\Items\Item;
use App\Core\Tenancy\Models\Branch;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Loyalty\Models\Promotion;
use Modules\Loyalty\Promotions\PromotionEngine;

/**
 * POST loyalty/promotions/preview (LOY-13): a sample cart evaluated with
 * the same engine as the till, against a draft promotion (`promotion`,
 * the PromotionRequest fields) and/or saved ones (`promotion_ids`), at a
 * branch, for a customer, with coupons, at a time (default now).
 *
 *   {company_id, branch_id?, party_id?, coupons?: [...], at?,
 *    promotion?: {type, ...}, promotion_ids?: [...],
 *    lines: [{id?, item_id, qty, unit_price_minor}], currency}
 */
class PreviewPromotionRequest extends LoyaltyRequest
{
    public function authorize(): bool
    {
        $this->authorizeAnywhere('loyalty.promotion.view', 'loyalty.promotion.create', 'loyalty.promotion.edit');

        return true;
    }

    public function rules(): array
    {
        $draft = [];

        foreach (PromotionRequest::definitionRules() as $field => $rules) {
            $draft["promotion.{$field}"] = $rules;
        }

        return [
            'company_id' => ['required', 'uuid', Rule::exists('companies', 'id')],
            'branch_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('branches', 'id')],
            'party_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('parties', 'id')],
            'currency' => ['required', 'string', 'regex:/^[A-Z]{3}\z/'],
            'coupons' => ['sometimes', 'array', 'max:10'],
            'coupons.*' => ['string', 'max:40'],
            'at' => ['sometimes', 'date'],
            'promotion' => ['sometimes', 'nullable', 'array'],
            'promotion.type' => ['required_with:promotion', 'string', Rule::in(PromotionEngine::TYPES)],
            ...$draft,
            'promotion_ids' => ['sometimes', 'array', 'max:50'],
            'promotion_ids.*' => ['uuid', 'distinct'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.id' => ['sometimes', 'string', 'max:64'],
            'lines.*.item_id' => ['required', 'uuid'],
            'lines.*.qty' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,6})?\z/'],
            'lines.*.unit_price_minor' => self::minor(positive: false),
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $company = $this->input('company_id');
            abort_unless($this->access()->allows($this->user(), 'loyalty.promotion.view', $company)
                || $this->access()->allows($this->user(), 'loyalty.promotion.create', $company), 404);

            if ($this->filled('branch_id') && ! Branch::query()->whereKey($this->input('branch_id'))->where('company_id', $company)->exists()) {
                $validator->errors()->add('branch_id', __('loyalty.validation.branches'));
            }

            $items = array_unique(array_column($this->input('lines'), 'item_id'));

            if (count($items) !== Item::query()->whereIn('id', $items)->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $company))->count()) {
                $validator->errors()->add('lines', __('loyalty.validation.items'));
            }

            $ids = $this->input('promotion_ids', []);

            if (count($ids) !== Promotion::query()->whereIn('id', $ids)->where('company_id', $company)->count()) {
                $validator->errors()->add('promotion_ids', __('loyalty.validation.promotions'));
            }

            if ($this->filled('promotion')) {
                PromotionRequest::checkDefinition($validator, (array) $this->input('promotion'), (string) $this->input('promotion.type'), $company);
            }
        }];
    }
}
