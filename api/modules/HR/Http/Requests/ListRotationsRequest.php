<?php

namespace Modules\HR\Http\Requests;

/** GET hr/rotations (HR-16): rows of the companies the reader reads with `hr.roster.view`. */
class ListRotationsRequest extends SetupListRequest
{
    protected function viewPermission(): string
    {
        return 'hr.roster.view';
    }

    protected function archivable(): bool
    {
        return true;
    }
}
