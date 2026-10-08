<?php

namespace App\Core\MasterData\Items\Http\Requests;

use App\Core\Lists\Http\ListsRecords;
use App\Core\Lists\ListDefinition;
use App\Core\MasterData\Items\Http\Lists\UomList;
use App\Core\MasterData\Items\UomPolicy;
use Illuminate\Foundation\Http\FormRequest;

/**
 * MD-02: the tenant's units (`core.uom.view|edit` anywhere); `?status`,
 * `?per_page`, `?search=` (code or name), `?sort` and an export
 * (`?format`, `?columns[]`; UomList, EXP-01).
 */
class ListUomsRequest extends FormRequest
{
    use ListsRecords;

    public function list(): ListDefinition
    {
        return new UomList;
    }

    public function authorize(): bool
    {
        return app(UomPolicy::class)->view($this->user());
    }

    public function rules(): array
    {
        return [
            ...$this->listRules(),
            'search' => ['sometimes', 'string', 'max:100'],
        ];
    }
}
