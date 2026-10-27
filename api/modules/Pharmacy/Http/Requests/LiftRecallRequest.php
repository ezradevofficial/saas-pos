<?php

namespace Modules\Pharmacy\Http\Requests;

use Illuminate\Validation\Validator;
use Modules\Pharmacy\Models\Lot;
use Modules\Pharmacy\Models\Recall;
use Modules\Pharmacy\Support\Access;

/** POST pharmacy/recalls/{recall}/lift (PHA-22): `pharmacy.recall.lift`; `{reason}`. */
class LiftRecallRequest extends PharmacyRequest
{
    protected function permission(): string
    {
        return 'pharmacy.recall.lift';
    }

    /** Lifting unblocks lots in every company the recall reached, so the right must reach all of them (RBAC-04). */
    public function after(): array
    {
        return [...parent::after(), function (Validator $validator) {
            // An already lifted recall reaches nothing (the controller answers 409).
            if ($validator->errors()->isNotEmpty() || $this->route('pharmacy_recall')->status !== Recall::OPEN) {
                return;
            }

            $companies = Lot::query()->where('recall_id', $this->route('pharmacy_recall')->id)->distinct()->pluck('company_id')->all();
            app(Access::class)->requireCompanies($this->user(), $this->permission(), $companies);
        }];
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:1000']];
    }
}
