<?php

namespace Modules\HR\Http\Requests;

/** POST hr/shifts (HR-16): `hr.roster.manage` at the company. */
class StoreShiftRequest extends SetupStoreRequest
{
    protected function permission(): string
    {
        return 'hr.roster.manage';
    }

    protected function fields(): array
    {
        return ShiftRequest::FIELDS(true);
    }
}
