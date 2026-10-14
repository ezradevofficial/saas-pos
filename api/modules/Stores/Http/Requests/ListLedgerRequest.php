<?php

namespace Modules\Stores\Http\Requests;

use App\Core\Lists\ListDefinition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Stores\Http\Lists\LedgerList;
use Modules\Stores\Models\Movement;

/**
 * GET stores/ledger (STR-10): `stores.ledger.view`; the store ledger at
 * the reader's stores; `?item=`, `?kind=`, `?department=`,
 * `?cost_centre=`, `?from=`, `?to=`, `?search=` (item code or name, the
 * document's number).
 */
class ListLedgerRequest extends FormRequest
{
    use ListsStores;

    public function list(): ListDefinition
    {
        return new LedgerList;
    }

    protected function viewPermission(): string
    {
        return 'stores.ledger.view';
    }

    protected function extraRules(): array
    {
        return ['kind' => ['sometimes', 'string', Rule::in(Movement::KINDS)]];
    }
}
