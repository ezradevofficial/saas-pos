<?php

namespace Modules\CRM\Http\Requests;

use App\Core\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Modules\CRM\Models\Pipeline;
use Modules\CRM\Models\Stage;
use Modules\CRM\Support\CrmAccess;

/**
 * The CRM configuration's writes (CRM-01 to CRM-06, CRM-11): settings,
 * pipelines and stages, options, teams, assignment rules and lead forms of
 * one company, with `crm.settings.manage` at that company (403). A record
 * or company of which the user holds no CRM permission is not found (404);
 * an unknown company in the body fails validation (422).
 */
abstract class SetupRequest extends FormRequest
{
    /** The permissions that let a user reach a company's CRM configuration. */
    public const REACH = ['crm.lead.view', 'crm.opportunity.view', 'crm.quote.view', 'crm.activity.view', 'crm.target.view', 'crm.settings.manage'];

    /** The route parameter naming the record (null for a create). */
    abstract protected function parameter(): ?string;

    public function authorize(): bool
    {
        $company = $this->company();

        if ($company === null) {
            // An unknown or malformed company id: the rules answer 422.
            return app(CrmAccess::class)->anywhere($this->user(), 'crm.settings.manage');
        }

        $reached = app(CrmAccess::class)->companies($this->user(), ...self::REACH);
        abort_unless($reached === null || in_array($company->id, $reached, true), 404);

        return app(CrmAccess::class)->canAtCompany($this->user(), 'crm.settings.manage', $company->id);
    }

    public function record(): ?Model
    {
        $parameter = $this->parameter();
        $record = $parameter === null ? null : $this->route($parameter);

        return $record instanceof Model ? $record : null;
    }

    /** The company acted at: the route's, the record's, or the body's `company_id`. */
    public function company(): ?Company
    {
        $company = $this->route('company');

        if ($company instanceof Company) {
            return $company;
        }

        $record = $this->record();

        if ($record instanceof Stage) {
            return Company::query()->find(Pipeline::query()->whereKey($record->pipeline_id)->value('company_id'));
        }

        if ($record !== null) {
            return Company::query()->find($record->getAttribute('company_id'));
        }

        $id = $this->input('company_id');

        return is_string($id) && Str::isUuid($id) ? Company::query()->find($id) : null;
    }

    public function rules(): array
    {
        return [];
    }
}
