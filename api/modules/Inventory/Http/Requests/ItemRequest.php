<?php

namespace Modules\Inventory\Http\Requests;

use App\Core\MasterData\Items\Item;
use App\Core\Rbac\ScopeResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Inventory\Models\ItemSetting;

/**
 * GET inventory/items/{item} (`inventory.stock.view`), PUT
 * inventory/items/{item}/settings `{tracking, track_expiry?}` and PUT
 * inventory/items/{item}/variant `{parent_item_id, attributes}`
 * (`inventory.item.configure`; INV-01, INV-02). The item is found under
 * row-level security (another tenant's is not found); an item the user
 * may not see (core.item.view, MD-02) is not found either.
 */
class ItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_unless($this->user()->can('view', $this->item()), 404);

        $permission = $this->isMethod('GET') ? 'inventory.stock.view' : 'inventory.item.configure';

        return app(ScopeResolver::class)->can($this->user(), $permission);
    }

    public function item(): Item
    {
        return $this->route('item');
    }

    public function rules(): array
    {
        return match ($this->route()->getActionMethod()) {
            'settings' => [
                'tracking' => ['required', 'string', Rule::in(ItemSetting::TRACKING)],
                'track_expiry' => ['sometimes', 'boolean'],
            ],
            'variant' => [
                'parent_item_id' => ['present', 'nullable', 'uuid', Rule::exists('items', 'id')->whereNull('archived_at')],
                'attributes' => ['required_with:parent_item_id', 'array', 'max:5'],
                'attributes.*' => ['required', 'string', 'max:60'],
            ],
            default => [],
        };
    }

    /** @return array<string, string> attribute name => value, names trimmed and lower case */
    public function variantAttributes(): array
    {
        $attributes = [];

        foreach ((array) $this->validated('attributes', []) as $name => $value) {
            $attributes[mb_strtolower(trim((string) $name))] = trim((string) $value);
        }

        return $attributes;
    }
}
