<?php

namespace Modules\CRM\CustomFields;

use Modules\CRM\Models\Opportunity;

/** CF-01, CRM-33: opportunities carry custom fields. */
class OpportunityEntity extends RecordEntity
{
    public const KEY = 'crm_opportunity';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'crm.custom_fields.opportunity';
    }

    public function model(): string
    {
        return Opportunity::class;
    }

    protected function resource(): string
    {
        return 'opportunity';
    }
}
