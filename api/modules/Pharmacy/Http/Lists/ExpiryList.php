<?php

namespace Modules\Pharmacy\Http\Lists;

/** PHA-10, PHA-24: the expiry report: lots with stock that expired or expire within the window, earliest first. */
class ExpiryList extends LotList
{
    public function name(): string
    {
        return 'pharmacy-expiry';
    }

    public function auditAction(): string
    {
        return 'pharmacy.report.expiry.export';
    }

    public function title(array $filters): string
    {
        return __('pharmacy.lists.expiry');
    }
}
