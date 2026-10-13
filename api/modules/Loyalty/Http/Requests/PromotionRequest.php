<?php

namespace Modules\Loyalty\Http\Requests;

use App\Core\Http\ApiException;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\ItemCategory;
use App\Core\Tenancy\Models\Branch;
use Brick\Math\BigDecimal;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Loyalty\Models\Promotion;
use Modules\Loyalty\Promotions\PromotionEngine;

/**
 * POST loyalty/promotions (`loyalty.promotion.create`) and PATCH
 * loyalty/promotions/{loyalty_promotion} (`loyalty.promotion.edit`; the
 * type never changes) (LOY-08). Also checks a promotion sent for a preview
 * (LOY-13, PreviewPromotionRequest). Body:
 *
 *   {company_id, name, description?, type, priority?, status?: draft|active,
 *    percent?, amount_minor?, currency?, buy_qty?, get_qty?,
 *    targets?: {items, categories}, get_targets?: {items, categories}|null,
 *    components?: [{item_id, qty}], min_spend_minor?, branch_ids?, customer_tags?,
 *    schedule?: {starts_on, ends_on, days, time_from, time_to}, requires_coupon?}
 *
 * Items, categories and branches are the company's (or shared); amounts
 * are minor-unit digit strings; percentages decimal strings, at most 4
 * decimals, above 0 and at most 100.
 */
class PromotionRequest extends LoyaltyRequest
{
    public function authorize(): bool
    {
        $promotion = $this->route('loyalty_promotion');

        if ($promotion !== null) {
            $this->authorizeAt($promotion->company_id, ['loyalty.promotion.view', 'loyalty.promotion.edit'], 'loyalty.promotion.edit');
        } else {
            $this->authorizeAnywhere('loyalty.promotion.create');
        }

        return true;
    }

    public function rules(): array
    {
        $creating = $this->route('loyalty_promotion') === null;

        return [
            'company_id' => [$creating ? 'required' : 'prohibited', 'uuid', Rule::exists('companies', 'id')->whereNull('archived_at')],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'type' => [$creating ? 'required' : 'prohibited', 'string', Rule::in(PromotionEngine::TYPES)],
            'status' => [$creating ? 'sometimes' : 'prohibited', 'string', Rule::in([Promotion::DRAFT, Promotion::ACTIVE])],
            ...self::definitionRules(),
        ];
    }

    /** The definition's fields (shared with the preview). */
    public static function definitionRules(): array
    {
        $ids = fn (string $table) => ['sometimes', 'array', 'max:500'];

        return [
            'priority' => ['sometimes', 'integer', 'between:-1000,1000'],
            'percent' => ['sometimes', 'nullable', 'string', 'regex:/^\d{1,3}(\.\d{1,4})?\z/'],
            'amount_minor' => ['sometimes', ...self::minor(required: false)],
            'currency' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Z]{3}\z/', Rule::exists('tenant_currencies', 'code')->where('active', true)],
            'buy_qty' => ['sometimes', 'nullable', 'integer', 'between:1,1000'],
            'get_qty' => ['sometimes', 'nullable', 'integer', 'between:1,1000'],
            'targets' => ['sometimes', 'array:items,categories'],
            'targets.items' => $ids('items'),
            'targets.items.*' => ['uuid', 'distinct'],
            'targets.categories' => $ids('item_categories'),
            'targets.categories.*' => ['uuid', 'distinct'],
            'get_targets' => ['sometimes', 'nullable', 'array:items,categories'],
            'get_targets.items' => $ids('items'),
            'get_targets.items.*' => ['uuid', 'distinct'],
            'get_targets.categories' => $ids('item_categories'),
            'get_targets.categories.*' => ['uuid', 'distinct'],
            'components' => ['sometimes', 'array', 'max:20'],
            'components.*.item_id' => ['required', 'uuid', 'distinct'],
            'components.*.qty' => ['required', 'integer', 'between:1,1000'],
            'min_spend_minor' => ['sometimes', ...self::minor(required: false)],
            'branch_ids' => ['sometimes', 'array', 'max:500'],
            'branch_ids.*' => ['uuid', 'distinct'],
            'customer_tags' => ['sometimes', 'array', 'max:50'],
            'customer_tags.*' => ['string', 'max:50', 'distinct'],
            'schedule' => ['sometimes', 'array:starts_on,ends_on,days,time_from,time_to'],
            'schedule.starts_on' => ['nullable', 'date_format:Y-m-d'],
            'schedule.ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:schedule.starts_on'],
            'schedule.days' => ['nullable', 'array', 'max:7'],
            'schedule.days.*' => ['integer', 'between:1,7', 'distinct'],
            'schedule.time_from' => ['nullable', 'required_with:schedule.time_to', 'date_format:H:i'],
            'schedule.time_to' => ['nullable', 'required_with:schedule.time_from', 'date_format:H:i', 'different:schedule.time_from'],
            'requires_coupon' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $promotion = $this->route('loyalty_promotion');

            // A new promotion belongs to a company where the user may create one.
            if ($promotion === null && ! $this->access()->allows($this->user(), 'loyalty.promotion.create', $this->input('company_id'))) {
                throw new ApiException(403, 'forbidden', __('rbac.forbidden'));
            }

            self::checkDefinition($validator, [...($promotion?->definition ?? []), ...$this->validated()], $promotion?->type ?? $this->input('type'), $promotion?->company_id ?? $this->input('company_id'));
        }];
    }

    /** Type-specific fields present and every reference the company's. */
    public static function checkDefinition(Validator $validator, array $values, string $type, string $companyId): void
    {
        $missing = fn (string $field) => $validator->errors()->add($field, __('loyalty.validation.required_for_type', ['type' => __('loyalty.promotion.types.'.$type)]));
        $percent = $values['percent'] ?? null;

        if (in_array($type, ['percent', 'buy_x_get_y'], true) && ($percent === null || ! BigDecimal::of((string) $percent)->isPositive() || BigDecimal::of((string) $percent)->isGreaterThan(100))) {
            $validator->errors()->add('percent', __('loyalty.validation.percent'));
        }

        if (in_array($type, ['amount_off', 'bundle'], true) && ($values['amount_minor'] ?? null) === null) {
            $missing('amount_minor');
        }

        if ((in_array($type, ['amount_off', 'bundle'], true) || ($values['min_spend_minor'] ?? null) !== null) && ($values['currency'] ?? null) === null) {
            $missing('currency');
        }

        if ($type === 'buy_x_get_y' && (($values['buy_qty'] ?? null) === null || ($values['get_qty'] ?? null) === null)) {
            $missing('buy_qty');
        }

        if ($type === 'bundle' && count($values['components'] ?? []) < 1) {
            $missing('components');
        }

        $itemIds = [...($values['targets']['items'] ?? []), ...($values['get_targets']['items'] ?? []), ...array_column($values['components'] ?? [], 'item_id')];
        $categoryIds = [...($values['targets']['categories'] ?? []), ...($values['get_targets']['categories'] ?? [])];
        $branchIds = $values['branch_ids'] ?? [];

        if (count(array_unique($itemIds)) !== Item::query()->whereIn('id', $itemIds)->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $companyId))->count()) {
            $validator->errors()->add('targets.items', __('loyalty.validation.items'));
        }

        if (count(array_unique($categoryIds)) !== ItemCategory::query()->whereIn('id', $categoryIds)->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $companyId))->count()) {
            $validator->errors()->add('targets.categories', __('loyalty.validation.categories'));
        }

        if (count(array_unique($branchIds)) !== Branch::query()->whereIn('id', $branchIds)->where('company_id', $companyId)->count()) {
            $validator->errors()->add('branch_ids', __('loyalty.validation.branches'));
        }
    }
}
