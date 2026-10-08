<?php

namespace App\Core\MasterData\Dimensions\Http\Requests;

/** TEN-06: archive or restore a department, cost centre or project (`core.dimension.archive`). No body. */
class DimensionActionRequest extends DimensionRequest
{
    protected bool $edits = true;

    protected string $action = 'archive';
}
