<?php

namespace Modules\HR\Http\Requests;

/** GET hr/rosters (HR-17): rows of the companies the reader reads with `hr.roster.view`. */
class ListRostersRequest extends SetupListRequest
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
