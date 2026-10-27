<?php

namespace Modules\Hotel\Http\Requests;

/** GET hotel/reports/folio-balances (HOT-31): `hotel.report.view` at the reader's branches; the folio list with its filters, only folios with a balance. */
class FolioBalancesReportRequest extends ListFoliosRequest
{
    protected function permission(): string
    {
        return 'hotel.report.view';
    }
}
