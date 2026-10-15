<?php

namespace Modules\FixedAssets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\FixedAssets\Models\Asset;
use Modules\FixedAssets\Support\Access;

/**
 * FA-29, RBAC-04: a request on one asset (`{fa_asset}`): not found unless
 * the user holds `fixed_assets.asset.view` at its location, so ids out of
 * scope are never confirmed; then the action's permission there (403).
 * GET fixed-assets/assets/{id}[/schedule|history|readings|documents]
 * need only the view.
 */
class AssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_unless($this->access()->at($this->user(), $this->viewPermission(), $this->asset()), 404);

        return $this->permission() === null || $this->access()->at($this->user(), $this->permission(), $this->asset());
    }

    public function rules(): array
    {
        return [];
    }

    public function asset(): Asset
    {
        return $this->route('fa_asset');
    }

    /** The action's permission at the asset's location, or null for a read. */
    protected function permission(): ?string
    {
        return null;
    }

    protected function viewPermission(): string
    {
        return 'fixed_assets.asset.view';
    }

    protected function access(): Access
    {
        return app(Access::class);
    }
}
