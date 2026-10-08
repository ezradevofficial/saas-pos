<?php

namespace App\Core\MasterData\Items\Http\Requests;

use App\Core\MasterData\Http\Requests\ListsArchivable;
use App\Core\MasterData\Items\UomPolicy;
use Illuminate\Foundation\Http\FormRequest;

/** MD-02: the tenant's units (`core.uom.view|edit` anywhere); `?status`, `?per_page`. */
class ListUomsRequest extends FormRequest
{
    use ListsArchivable;

    public function authorize(): bool
    {
        return app(UomPolicy::class)->view($this->user());
    }

    public function rules(): array
    {
        return $this->listRules();
    }
}
