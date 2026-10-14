<?php

namespace Modules\HR\Http\Requests;

/** POST hr/rotations (HR-16): `hr.roster.manage` at the company. */
class StoreRotationRequest extends SetupStoreRequest
{
    protected function permission(): string
    {
        return 'hr.roster.manage';
    }

    protected function fields(): array
    {
        return RotationRequest::rulesFor(true);
    }
}
