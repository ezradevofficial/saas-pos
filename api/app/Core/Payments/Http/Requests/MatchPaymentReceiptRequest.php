<?php

namespace App\Core\Payments\Http\Requests;

use App\Core\MasterData\Http\Requests\CompanyResourceRequest;
use App\Core\Payments\Models\PaymentIntent;
use App\Core\Payments\Models\PaymentReceipt;
use App\Core\Tenancy\Models\Company;
use Illuminate\Validation\Rule;

/**
 * POST payment-receipts/{payment_receipt}/match (`core.payment.match` at
 * the receipt's company): `payment_intent_id`, an intent of the same
 * company (a manual payment to verify, or an STK push that never got its
 * answer).
 */
class MatchPaymentReceiptRequest extends CompanyResourceRequest
{
    protected string $resource = 'payment';

    protected array $readActions = ['view', 'match'];

    protected bool $edits = true;

    protected string $action = 'match';

    protected function targetCompany(): Company
    {
        return Company::query()->findOrFail($this->receipt()->company_id);
    }

    public function rules(): array
    {
        return [
            'payment_intent_id' => ['required', 'uuid', Rule::exists('payment_intents', 'id')->where('company_id', $this->receipt()->company_id)],
        ];
    }

    public function receipt(): PaymentReceipt
    {
        return $this->route('payment_receipt');
    }

    public function intent(): PaymentIntent
    {
        return PaymentIntent::query()->findOrFail($this->validated('payment_intent_id'));
    }
}
