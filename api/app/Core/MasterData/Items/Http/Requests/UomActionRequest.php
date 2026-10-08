<?php

namespace App\Core\MasterData\Items\Http\Requests;

/** TEN-06: archive or restore a unit (`core.uom.edit` at tenant scope). No body. */
class UomActionRequest extends UomRequest
{
    protected bool $edits = true;
}
