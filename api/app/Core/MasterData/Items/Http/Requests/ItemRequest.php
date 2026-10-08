<?php

namespace App\Core\MasterData\Items\Http\Requests;

use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\ItemPolicy;
use Illuminate\Foundation\Http\FormRequest;

/**
 * MD-02: one item. Not reached with any item permission: 404 (RBAC-04);
 * reached without this request's ability: 403 (ItemPolicy).
 */
class ItemRequest extends FormRequest
{
    /** The ItemPolicy ability: view, update, archive. */
    protected string $ability = 'view';

    public function authorize(): bool
    {
        $policy = app(ItemPolicy::class);

        abort_unless($policy->reach($this->user(), $this->item()), 404);

        return $policy->{$this->ability}($this->user(), $this->item());
    }

    public function rules(): array
    {
        return [];
    }

    public function item(): Item
    {
        return $this->route('item');
    }
}
