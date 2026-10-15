<?php

namespace Modules\HR\Http\Requests;

/** GET hr/positions (HR-08): rows of the companies the reader reads with `hr.position.view`. */
class ListPositionsRequest extends SetupListRequest
{
    protected function viewPermission(): string
    {
        return 'hr.position.view';
    }

    protected function archivable(): bool
    {
        return true;
    }
}
