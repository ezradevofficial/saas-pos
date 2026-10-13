<?php

namespace Modules\Accounting\Http\Requests;

/** GET companies/{company}/accounting/settings (ACC-02). */
class ShowSettingsRequest extends AccountingRequest
{
    protected function permission(): string
    {
        return 'accounting.account.view';
    }
}
