<?php

namespace Modules\Accounting\Http\Requests;

/** GET accounting/documents/{acc_document}, and its PDF. */
class ShowDocumentRequest extends AccountingRequest
{
    protected function permission(): string
    {
        return 'accounting.invoice.view';
    }
}
