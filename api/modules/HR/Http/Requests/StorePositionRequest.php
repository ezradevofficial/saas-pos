<?php

namespace Modules\HR\Http\Requests;

/** POST hr/positions (HR-08): `hr.position.manage` at the company. */
class StorePositionRequest extends SetupStoreRequest
{
    protected function permission(): string
    {
        return 'hr.position.manage';
    }

    protected function fields(): array
    {
        return PositionRequest::rulesFor(true);
    }
}
