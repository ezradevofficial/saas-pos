<?php

namespace Modules\CRM\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\CRM\Models\Activity;
use Modules\CRM\Models\Lead;
use Modules\CRM\Models\Opportunity;
use Modules\CRM\Models\Quote;
use Modules\CRM\Models\ScopedRecord;
use Modules\CRM\Support\CrmAccess;

/**
 * GET crm/{leads|opportunities|quotes}/{id} (and the base of the record
 * action requests): one record for a user who sees it (CRM-08). Anything
 * else is not found, so ids out of scope are never confirmed.
 */
class ShowRecordRequest extends FormRequest
{
    public const PARAMETERS = ['crm_lead' => 'lead', 'crm_opportunity' => 'opportunity', 'crm_quote' => 'quote', 'crm_activity' => 'activity'];

    public function authorize(): bool
    {
        abort_unless(app(CrmAccess::class)->sees($this->user(), $this->record(), $this->resource()), 404);

        return true;
    }

    public function rules(): array
    {
        return [];
    }

    public function record(): ScopedRecord
    {
        foreach (array_keys(self::PARAMETERS) as $parameter) {
            $record = $this->route($parameter);

            if ($record instanceof ScopedRecord) {
                return $record;
            }
        }

        abort(404);
    }

    /** `lead`, `opportunity`, `quote` or `activity` */
    public function resource(): string
    {
        return match (true) {
            $this->record() instanceof Lead => 'lead',
            $this->record() instanceof Opportunity => 'opportunity',
            $this->record() instanceof Quote => 'quote',
            $this->record() instanceof Activity => 'activity',
        };
    }

    /** Whether the user holds `crm.{resource}.{action}` at the record's branch. */
    public function mayAt(string $action): bool
    {
        return app(CrmAccess::class)->canAt($this->user(), "crm.{$this->resource()}.{$action}", $this->record()->branch_id);
    }
}
