<?php

namespace Modules\Accounting\Http\Requests;

/** POST accounting/documents/{acc_document}/post (ACC-09). */
class PostDocumentRequest extends AccountingRequest
{
    protected function permission(): string
    {
        return 'accounting.invoice.post';
    }
}
