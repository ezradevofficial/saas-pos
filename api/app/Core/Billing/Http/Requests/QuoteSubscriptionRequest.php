<?php

namespace App\Core\Billing\Http\Requests;

use App\Core\Billing\Catalogue\Pricing\ModuleOffer;
use App\Core\Billing\Catalogue\Pricing\QuoteRequest;
use Illuminate\Validation\Rule;

/**
 * SUB-02: a plan to quote (the Owner's): modules, quantities per unit,
 * cycle, bundle and optionally a coupon code. Unknown modules and
 * bundles are refused by the pricing engine with the catalogue's reason.
 */
class QuoteSubscriptionRequest extends SubscriptionRequest
{
    protected string $permission = 'core.subscription.manage';

    protected bool $ownerOnly = true;

    public function rules(): array
    {
        return [
            'modules' => ['present', 'array', 'max:40'],
            'modules.*' => ['string', 'max:60', 'distinct'],
            'quantities' => ['sometimes', 'array:'.implode(',', ModuleOffer::UNITS)],
            'quantities.*' => ['integer', 'min:0', 'max:100000'],
            'cycle' => ['required', 'string', Rule::in(QuoteRequest::CYCLES)],
            'bundle' => ['nullable', 'string', 'max:60'],
            'coupon' => ['nullable', 'string', 'max:40'],
        ];
    }

    /** @return array{modules: list<string>, quantities: array<string, int>, cycle: string, bundle: ?string} */
    public function plan(): array
    {
        return [
            'modules' => array_values($this->validated('modules', [])),
            'quantities' => $this->validated('quantities', []),
            'cycle' => $this->validated('cycle'),
            'bundle' => $this->validated('bundle'),
        ];
    }

    public function coupon(): ?string
    {
        return $this->validated('coupon');
    }
}
