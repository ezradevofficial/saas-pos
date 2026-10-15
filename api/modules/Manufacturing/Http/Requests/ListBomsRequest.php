<?php

namespace Modules\Manufacturing\Http\Requests;

use App\Core\Lists\ListDefinition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Manufacturing\Http\Lists\BomList;
use Modules\Manufacturing\Models\Bom;

/**
 * GET manufacturing/boms (MFG-02, MFG-27): `manufacturing.bom.view`; BOMs
 * of the companies where the reader holds it; `?kind=`, `?item=`,
 * `?company=`, `?status=active|archived|all` (default active),
 * `?search=` (item code or name, BOM name).
 */
class ListBomsRequest extends FormRequest
{
    use ListsManufacturing;

    public function list(): ListDefinition
    {
        return new BomList;
    }

    protected function viewPermission(): string
    {
        return 'manufacturing.bom.view';
    }

    protected function byCompany(): bool
    {
        return true;
    }

    protected function extraRules(): array
    {
        return [
            'kind' => ['sometimes', 'string', Rule::in(Bom::KINDS)],
            'status' => ['sometimes', 'string', 'in:active,archived,all'],
        ];
    }
}
