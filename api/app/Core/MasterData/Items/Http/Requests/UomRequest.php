<?php

namespace App\Core\MasterData\Items\Http\Requests;

use App\Core\MasterData\Items\Uom;
use App\Core\MasterData\Items\UomPolicy;
use Illuminate\Foundation\Http\FormRequest;

/**
 * MD-02: one unit of measure. Read with `core.uom.view|edit` anywhere;
 * changed with `core.uom.edit` at tenant scope (UomPolicy). A user with
 * no unit permission gets 404.
 */
class UomRequest extends FormRequest
{
    protected bool $edits = false;

    public function authorize(): bool
    {
        $policy = app(UomPolicy::class);

        abort_unless($policy->view($this->user(), $this->uom()), 404);

        return ! $this->edits || $policy->edit($this->user(), $this->uom());
    }

    public function rules(): array
    {
        return [];
    }

    public function uom(): Uom
    {
        return $this->route('uom');
    }
}
