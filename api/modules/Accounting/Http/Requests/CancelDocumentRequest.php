<?php

namespace Modules\Accounting\Http\Requests;

/** POST accounting/documents/{acc_document}/cancel: a draft (TEN-06). */
class CancelDocumentRequest extends AccountingRequest
{
    protected function permission(): string
    {
        return 'accounting.invoice.create';
    }
}
