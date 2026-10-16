<?php

namespace Modules\CRM\CustomFields;

use Modules\CRM\Models\Lead;

/** CF-01, CRM-33: leads carry custom fields. */
class LeadEntity extends RecordEntity
{
    public const KEY = 'crm_lead';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'crm.custom_fields.lead';
    }

    public function model(): string
    {
        return Lead::class;
    }

    protected function resource(): string
    {
        return 'lead';
    }
}
