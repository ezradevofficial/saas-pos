<?php

namespace Modules\CRM\Http\Requests;

/**
 * GET crm/quotes/{id}/pdf and GET crm/quotes/{id}/shares (CRM-22): not
 * found unless the user sees the quote (CRM-08); then `crm.quote.send` at
 * its branch (403). No query parameters.
 */
class QuoteOutputRequest extends ShowRecordRequest
{
    public function authorize(): bool
    {
        parent::authorize();

        return $this->mayAt('send');
    }
}
